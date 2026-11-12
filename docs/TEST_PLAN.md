# TEST PLAN

## Testing layers
Unit → Feature → Integration → Database integrity → Concurrency/idempotency → Security → Realtime/load → Disaster recovery → Full mock election → Production readiness.

## Critical vote tests
- Double click/duplicate request.
- Same submission UUID replay.
- Simultaneous submissions by same student.
- Timeout after commit.
- Timeout before commit.
- DB rollback during vote transaction.
- Pause during submission.
- Close during submission.
- Invalid contest/candidate/selection.
- Invalid abstention combination.
- Eligibility mismatch.
- Partial ballot prevention.

## Reconciliation tests
Participation = ballots, ballots contain valid items, aggregates equal independent calculation, live aggregate never becomes authoritative, finalized result reproducible.

## Realtime tests
Hundreds/thousands of viewers, vote bursts, duplicate broadcast, missed event, disconnect/reconnect, Reverb node failure, Redis/queue degradation.

## Security tests
Authorization, privilege escalation, CSRF/session behavior, XSS/injection, file uploads, rate limits, OAuth flows, admin approval/self-approval prevention, backup access.

## Import tests
Duplicate IDs, malformed rows, large files, college/course/year changes, missing students, re-imports, import failures/rollback, manual changes overridden by later official upload.

## Disaster tests
Database restore, backup corruption detection, production recovery procedure, application outage, queue failure, realtime failure, deployment rollback.

## Load tests
Use progressively increasing concurrent users, simultaneous submissions, realtime viewers, reporting workload, imports, and backup operations. Establish measurable limits before production.

## Phase 02 tests (authentication, admin authorization, approvals)
Automated with a fake Google provider; no real Google calls. Suites: `tests/Feature/Auth/*`, `tests/Feature/Approval/*`, `tests/Feature/StockAuthRemovalTest.php`.
- One Google login entry point (no credentials form, no separate admin login, exact authentication route inventory).
- Student login, first-link, repeat login by `sub`, INACTIVE student can authenticate, no student created, no Google data written, non-Data Center-loaded rows denied.
- Access Issue flow: same message for every denial; only verified institutional accounts can submit (non-institutional, look-alike and unverified accounts cannot); every problem type accepted/stored, invalid or missing type rejected, list is config-backed and fails closed when empty; `denial_reason` stays system-generated and independent of `problem_type`; no student created/changed, no access granted, single-use, validation, rate limit, no OAuth values stored/logged; admin read-only view (all required columns, readable labels, escaping).
* Administrator authentication: pre-authorized admin records exist independently of Data Center imports; there is no public admin registration path; unauthorized Google accounts cannot create admin records.
* Administrator first-link: a pre-authorized admin with `google_subject = NULL` can authenticate only when the Google email is verified and matches the pre-authorized `authorized_email`; the stable Google `sub` is then bound atomically.
* Administrator first-link failure cases: unverified email, unknown email, non-matching authorized email, malformed identity, conflicting identity, duplicate authorized email, and attempted binding to a `sub` already used by another identity are denied safely.
* Administrator repeat login uses the bound stable Google `sub`; an alternate `sub` does not replace an existing binding merely because the email matches.
* Data Center import never creates, modifies, or deletes administrator records.
* No public administrator registration, password registration, or self-promotion path exists.
* The current three-admin roster and fixed `BUKSU_COMELEC_IT_ADMIN` role are enforced as the current operational authorization policy without introducing a permanent database cardinality constraint.
* Admin route protection, Livewire persistent-middleware protection, cross-guard isolation, identity-conflict denial, and audit behavior remain tested as already specified.
* Student first-link remains separate and continues to require an existing Data Center-loaded student row with `google_subject = NULL`.
- Admin/student route protection, no admin-management routes, Livewire persistent-middleware boundary (real HTTP to the update endpoint; `Livewire::test()` bypasses it).
- Approvals: self-approve/reject refused, approval/rejection by another admin, atomic race, all-admin in-app notification in the same transaction, audit evidence, no sensitive data.
- Engine notes: the MySQL/MariaDB CHECK test skips on SQLite; the case-variant-duplicate-email test skips on case-insensitive engines. **Still to do:** a real Google OAuth smoke test on staging (documented, not part of the automated suite). Running on MySQL 8 is not a Phase 02 gate.

## Testing layers

Unit → Feature → Integration → Database integrity → Concurrency/idempotency → Security → Realtime/load → Disaster recovery → Full mock election → Production readiness.

The test strategy distinguishes between:

- design approval
- implementation
- verification
- production-readiness gates

Passing application tests does not by itself establish database integrity, ballot secrecy, concurrency safety, or production readiness.

* The implementation must test the distinction between administrator authorization and student Data Center provisioning: an administrator record must remain valid even though it is absent from all student import data, and importing student data must never create, discover, modify, or delete administrator records.

---

# 1. Core testing principles

The system must prove that authoritative election data remains correct under:

- normal voting
- duplicate requests
- retries
- transaction failure
- database failure
- concurrent submissions
- election pause/close races
- unauthorized database access
- invalid ballot content
- reconciliation anomalies
- result recalculation

Critical integrity must be demonstrated against the authoritative MySQL/MariaDB database engine rather than relying only on SQLite application tests.

---

# 2. Phase 01B regression

The canonical Phase 01B integrity suite remains the baseline schema regression test.

Expected result:

```text
76 passed / 0 failed

## Phase 03A tests (student master / Data Center import core)
All data is synthetic; no real student data is committed. Suites: `tests/Unit/StudentImport/*`, `tests/Feature/Import/*`, plus `database/verification/phase03a_import_concurrency.php`.
- Unit: header mapping (aliases, BOM, ignored `No.`/`Sex`), string-preserved identity (leading zeros, any length, numeric artifacts INVALID), year mapping (invalid/missing rejected), placement rule (course change => 1st Year; unchanged course keeps incoming year), case-only canonicalization, row classification, state machine (exact allowed transitions), CSV/XLSX readers (fail closed on bad encoding/corrupt file; zip-bomb size cap).
- Feature (validation): staging records batch/checksum/file and changes no authoritative data; raw row preserved; duplicates (identical/conflicting/case-only); case-only college inconsistency to review; validation creates no student records; eligible unknown IDs with supported 7-digit legacy or 10-digit current numeric formats classify as NEW for processing (the generated email convention remains provisional); unsupported IDs remain in NEEDS_EXCEPTION_REVIEW; re-validation rebuilds staging; CSV and XLSX classify identically; file-level failures end FAILED with a coarse reason.
- Feature (processing): existing-student placement updates and eligible NEW student creation with a provisional generated email + append-only history + `last_import_batch_id`; course-change rule; counters reconcile; status preserved/applied; later-batch re-import; missing-from-roster untouched; numeric batch ids are never chronology (outcome follows processing order for both id orders; the importer source contains no id comparison); replayed chunk is a no-op; finished batch cannot be reprocessed; `admin_users` untouched; existing students' institutional ID, institutional email, and Google subject are never overwritten; new-student identity and email values are set at creation using the provisional convention; Phase 02 student login compatibility (imported student first-links; INACTIVE not locked out by status); audit events carry counters only; no public route exposes staging.
- Failure/retry: a REAL database rejection mid-batch rolls back only its chunk, leaves the batch truthfully `PROCESSING`, counters equal committed rows; retry resumes without duplicating or redoing committed work; incomplete batch cannot complete (`INTEGRITY_MISMATCH`); jobs (queued with only the batch id, unique, duplicate delivery is a no-op, `failed()` hook marks `FAILED`, resume).
- Staging cleanup: successful staging stores exactly one source file; a database failure after the file was stored deletes that exact file and leaves no batch row; a throwing or `false`-returning cleanup never masks the original exception and is logged with the storage key only; a later batch never inherits an orphaned file; a file refused before the transaction stores nothing.
- Scale: a 7,966-row synthetic roster classified exactly as constructed, applied once per student, reconciled, replayed and re-imported without duplicates; the same rows as `.xlsx` classify identically. No performance threshold is asserted (none approved); timings are reported with `IMPORT_SCALE_REPORT=1`.
- Queue/recovery hardening: `retry_after` greater than both job timeouts (and `.env.example` documents it); `ValidateImportBatch::failed()` fails only a `VALIDATING` batch (coarse code, audited, no row data) and re-validation recovers it; the chunk processor applies nothing unless the locked batch is `PROCESSING` (including a worker whose batch was failed mid-run, then resumed without duplicates).
- Real concurrency (MySQL/MariaDB only, separate PHP processes): `php database/verification/phase03a_import_concurrency.php` runs N workers on one batch and a kill-then-restart scenario, and checks exact-once application, one enrollment per student and reconciling counters. It FAILS (deadlock 1213) if the batch-row lock is removed.

## Phase 03B tests (Data Center import UI)
All data is synthetic. Suite: `tests/Feature/Import/ImportUiTest.php` (plus the sidebar assertions in `tests/Feature/AdminDashboardTest.php`).
v2 regression tests: sync queue never leaves the page waiting (stage and re-validate); background-worker wait ends on state change even with frozen identical timestamps; wait capped at 120 s; poll markup has no `.visible`; idle STAGED/CONFIRMED do not poll; repeated actions and two admin sessions push one `ValidateImportBatch`; lock released after a sync run; job uniqueness contract; trimmed-whitespace wording.
- Access: guest redirected, student forbidden, route inside the `admin` middleware group, sidebar entry is a real active link, a student cannot drive the component through the Livewire endpoint or by calling actions with middleware bypassed.
- Staging: a valid CSV is staged with the authenticated admin as uploader (a second admin is recorded as that admin; no client-controlled uploader property exists), the stored file/checksum exist, and `ValidateImportBatch` is queued with the batch and admin ids; unsupported extensions, oversize files, missing file/year/semester are rejected before anything is staged; year/semester accept any text; a queue failure leaves a `STAGED` batch with a safe message.
- Listing and preview: counters/status/term/uploader shown from stored data, empty state, server-side batch and row pagination, classification filter validated against the known set, deep link by `?batch=`; normalized values, classification totals and issue codes render while a marker that exists only in `raw_row_json` never appears.
- Confirmation: confirm calls the service and queues exactly one `ProcessImportBatch`; rows that will not be applied require acknowledgement; nothing-applicable and repeated confirmation are refused; confirm/start/revalidate are refused (status unchanged, nothing queued) in every state the state machine does not allow; allowed recovery actions work; re-validation is never offered for a confirmed batch.
- States: processing progress from persisted `processed_at`; completed summary; unsupported-ID and exception-review rows are never shown as applied and are not created; eligible NEW rows are shown as applied only after processing; failed batches show a fixed coarse message (and a generic one for an unknown code), never technical detail; polling is rendered only while something is active.
- Scope: administrator records are unchanged; the Data Center import UI adds only the `admin/data-center` route. The separate read-only Student Masterlist route is covered in the following section. The Data Center component never selects raw rows or writes batch status.
- Browser (manual, headless Chromium, synchronous queue, synthetic file): upload, preview, acknowledgement, confirm, completion; no horizontal overflow at 1440, 768, 390 and 375 px. Not automated.

## Student Masterlist tests
All data is synthetic. Suite: `tests/Feature/Admin/StudentMasterlistTest.php` (plus the sidebar/route allow-lists in `AdminDashboardTest.php` and `RouteProtectionTest.php`).
- Authorization: guest redirected, student guard 403, admin 200, component refuses a non-admin; distinct sidebar link and the renamed "Student Import" link; no write route.
- Search: ID, partial ID, first/last/middle name, "last, first", any word order, case-insensitive; `%`, `_`, `!`, quotes and SQL text are literal; overlong input is cut; the search text is not in the URL.
- Filters: each filter and combinations; choices come from the data; courses follow the college; unknown values dropped; deep links; clear; empty and no-match states.
- Pagination: 25 per page, stable order, distinct pages, last page, rows beyond the page not rendered, page reset on search/filter change.
- Modal: closed by default; shows identity, email, placement, status and import information; "not loaded" case; close and Escape clear the selection; deep link and unknown id; list state kept while open.
- Privacy: no `google_subject`, raw row or other student's data in the page, modal or snapshot; the list shows no emails; component source has no write call, model or raw-row access; listing and opening write nothing.

## Phase 04A tests (election draft management)
All data is synthetic. Suite: `tests/Feature/Election/ElectionDraftTest.php` (plus allow-lists in `RouteProtectionTest.php` and `AdminDashboardTest.php`).
- Authorization: guest redirected, student 403, admin 200 on list/new/show; components refuse non-admins; policy `view`/`create`; broken roster; unknown or non-numeric id 404.
- Creation: DRAFT election with description, `type`, `template_key`, `creation_key`; version 1 linked; composite FK refusal; type and template independent; no contests/rules/candidates created; invalid input writes nothing; rollback then retry; audit carries no name/description.
- Duplicate submissions: same key twice, changed retry, upper-case key, cache flush, DB unique index, simulated lost race (loser rolls back, gets winner), other admin refused, locked page properties, second click no-op.
- Pages: form fields and template note, validation, redirect with message, failure shown without details.
- Workspace: saved details, template provenance, scope "Not configured yet", configuration status, read-only, any state.
- List: title, every state listed, Open link per row, state filter agrees with the query, unknown filter dropped, deep link, pagination 15, read-only, no personal data.
- Scope: sidebar current on all three pages; palette scoped; writes only through the service.
