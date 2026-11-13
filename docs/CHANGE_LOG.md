# CHANGE LOG

## 2026-09-26 — Architecture Baseline Planning
- Established reusable configurable election-engine objective.
- Locked MySQL as authoritative election data source.
- Locked atomic/idempotent vote submission and immutable cast ballots.
- Locked separation of voter participation from ballot selections.
- Locked receipt as proof of participation only.
- Locked student-facing live results to overall cast votes + anonymous candidate leaderboards.
- Locked horizontally scalable Reverb/Redis realtime architecture.
- Locked election-wide integrity lock and immutable historical elections.
- Locked searchable election history and configuration-only reuse from previous elections.
- Locked Data Center as current-student-data authority.
- Locked Student status to ACTIVE/INACTIVE only.
- Locked course-change rule: new course is 1st Year for COMELEC purposes.
- Locked controlled late-COR/election-specific eligibility exception concept.
- Locked candidate roster approval, snapshot, and lock.
- Locked tie handling: detect/flag; COMELEC President/authorized heads decide outside the engine.
- Locked automatic/downloadable encrypted backups and restore testing.
- Identified remaining institutional/open decisions; see `OPEN_DECISIONS.md`.

## 2026-09-28 — Phase 1A Domain Model & Database Architecture Approved

- Completed Phase 1A domain model and database architecture review.
- Approved the separation of voter identity/participation from anonymous ballot content.
- Approved anonymous but traceable ballot architecture using opaque ballot identifiers, ballot events, and separate ballot dispositions.
- Approved explicit per-contest `VOTE` / `ABSTAIN` responses; `SKIP` is not a domain state.
- Approved multi-seat voting where selecting fewer than the configured maximum remains a valid `VOTE`.
- Approved complete election abstention as participation with a committed ballot containing explicit `ABSTAIN` responses and zero candidate selections.
- Confirmed non-participation as the absence of both participation and ballot records.
- Approved frozen, non-identifying ballot reporting context for college/course/year/sector result breakdowns.
- Approved configurable result aggregation and immutable finalized result snapshots.
- Approved ballot immutability; exceptional ballot handling uses additive disposition/incident records rather than modifying the original cast ballot.
- Approved MySQL-first integrity enforcement with schema constraints, transactional validation, and reconciliation; no triggers proposed for Phase 1A.
- Approved ULID strategy for privacy-sensitive/externally referenced identifiers.
- Approved candidate identity as a separate thin `candidates` entity.
- Approved election-scoped parties unless institutional policy later establishes a persistent recognized-party registry.
- Marked Phase 1A complete and authorized progression to Phase 1B database/migration implementation.
- Remaining institutional/reporting decisions remain tracked in `OPEN_DECISIONS.md`.

## 2026-09-29 — Phase 02 Authentication & Admin Authorization (implemented; pending review)

- **Proposer:** implementation session, per approved Phase 02 plan. **Approval authority:** project owner (plan approved with the corrections recorded below).
- **Reason:** AUTH-031 Google OAuth foundation, AUTH-032 exactly-three admins, AUTH-033 student/admin identity separation, AUTH-034 proposal/approval workflow.
- ONE user-facing login entry point: "Continue with Google". No separate admin login, no passwords. Google OAuth via Laravel Socialite behind a `GoogleIdentityProvider` seam (fake in tests; no real Google calls in the automated suite). `composer.json` now requires `laravel/socialite` `^5.26`. **`composer.lock` is intentionally NOT part of this change and must be regenerated on a networked machine:** `composer update laravel/socialite --with-dependencies`, then `composer validate` and re-run the test suite.
- Two independent session guards, `student` and `admin`, backed by `students` and `admin_users`. Stock password/register/reset/verify/settings flows and `App\Models\User` removed. Forward migration drops `users` and `password_reset_tokens`; `sessions` and the historical `0001_01_01_000000` migration are untouched. No table had a foreign key to `users`.
- Student login: first-link by exact (case-insensitive) institutional email after `email_verified`, configured-domain check, an existing student row that came from the Data Center import (`students.last_import_batch_id IS NOT NULL`) and `google_subject IS NULL`; afterwards by Google `sub`. Login never creates a student and never writes Google data into student fields. An existing INACTIVE student may authenticate: `status` is not an authentication credential and no live `status` check decides eligibility (the locked election eligibility snapshot does). Dependency: the Phase 01C import must stamp `last_import_batch_id`, otherwise every student login fails closed.
- Admins: fixed role `BUKSU_COMELEC_IT_ADMIN`; pre-authorized records (see the 2026-09-30 administrator identity entries below, which supersede the original subject-first design). The current operational roster is re-checked at runtime on every admin login and request (fail closed). No admin-management routes or UI exist.
- A Google identity that resolves to both domains is denied in both (`IDENTITY_CONFLICT`, SECURITY audit).
- **Access Issue flow:** when Google authentication succeeds but the account is not let in (unknown or non-institutional account, unverified email, not Data Center-loaded, conflicts), the person is sent to one identical Access Issue page ("Student Access Issue") that never reveals admin/student/unknown. Only a Google-verified email on the configured institutional domain may SUBMIT a report; any other denied account sees the same page without a form and cannot create a student access ticket. The form has a required Problem Type (config-backed list, `comelec.access_issue_problem_types`, not a DB enum), an optional Student ID and a required Description; the verified email is filled from the Google identity and is not editable. `problem_type` (what the student says they need help with) is kept separate from the system-generated `denial_reason` (why authentication denied the login); the student can supply only the former. Reports go into `access_issue_reports` with the date/time. A report never creates or modifies a student, never grants access, and stores no token, raw Google `sub` or unverified email. Read-only admin list at `/admin/access-issues` (submitted at, problem type, student ID, institutional email, system denial reason, description; no status workflow yet). Failures with no usable Google identity (provider/state error, malformed identity) return to the login page with one generic message. Login / Access Reports (this Phase 02 mechanism: about being unable to authenticate) are distinct from the future General Reports (Phase 03: reports from authenticated users about other matters), which are NOT implemented here.
- A successful Google login REPLACES any existing session identity (student <-> admin); a denied attempt leaves the current session untouched.
- `EnsureAdmin` / `EnsureStudent` middleware, also registered as Livewire persistent middleware (Livewire 4 allow-list mechanism, verified against the installed package).
- Generic `change_requests` mechanism: requester cannot decide own request (policy, service pre-check, atomic UPDATE predicate, and DB CHECK on MySQL/MariaDB); atomic PENDING to APPROVED/REJECTED; all three admins notified in-app in the same transaction; creation/decision audited in the same transaction. No real action types and no approval thresholds were invented.
- Framework-merged default `web` guard, `users` provider and password broker are explicitly removed at runtime (Laravel 12 re-merges them beneath `config/auth.php`).
- **Migration impact:** `change_requests`, `notifications`, `access_issue_reports` added; CHECK on `admin_users.role` (MySQL/MariaDB); `users`/`password_reset_tokens` dropped (reversible).
- **Testing impact:** stock auth/settings/dashboard tests deleted with the features they tested; Phase 02 suites added. See `TEST_PLAN.md`.
- **Not claimed:** the Phase 02 gate is not marked passed. A real Google OAuth smoke test on staging remains to be done (documented, not automated). Phase 01C is untouched and remains in progress.

## Change process
Every material architecture or election-rule change must record date, reason, proposer, affected documents/code, approval authority, migration/testing impact, and resulting decision.

## 2026-09-30 — Phase 02 Administrator Identity Lifecycle Correction

* **Reason:** Documentation correction to align the approved Phase 02 administrator model with the final project decision.
* Administrator accounts are pre-authorized system records and are independent of the official Data Center student import.
* Administrators do not publicly register and cannot create their own administrator accounts through Google login.
* The current initial roster of three administrators is an operational roster, not a permanent database or application cardinality limit.
* Each administrator has a pre-authorized personal Google email before first login.
* `admin_users.google_subject` is nullable before first successful login and unique when populated.
* On first Google login, the verified Google email is matched to the pre-authorized administrator record and the stable Google `sub` is then bound to that existing record.
* Subsequent administrator authentication uses the bound stable Google `sub`; an existing binding is not replaced by an alternate `sub`.
* Data Center imports remain authoritative for student master data only and must never create or provision administrator accounts.
* This entry documents the approved design correction only. Code, migration, and test implementation changes are intentionally deferred to the implementation phase.

## 2026-09-30 — Phase 02 Administrator Identity Lifecycle: Implementation Revision

* **Reason:** implement the approved administrator model (pre-authorized records; Google login never creates an administrator). Supersedes the original subject-first implementation. **Approval authority:** project owner (audit and plan approved with four decisions, recorded below).
* **Lifecycle implemented:** pre-authorized administrator (`authorized_email`, `google_subject` NULL) -> verified Google login -> atomic first-link of the stable `sub` (conditional on `google_subject IS NULL`, backed by the UNIQUE index) -> subsequent authentication by the bound `sub`. An alternate `sub` presenting a bound administrator's email is denied and the binding is never replaced. Cross-domain (student/admin) conflicts remain fail-closed and audited. Audit events `auth.admin.first_link` / `auth.admin.login` carry no email or subject.
* **Provisioning:** `comelec:provision-admins` now takes `COMELEC_ADMIN_IDENTITIES` as `[{"authorized_email","display_name"}, ...]` (a configured `google_subject` is rejected). Emails are trimmed, lowercased and validated. It is idempotent and additive: it never rebinds, replaces, renames or deletes an existing admin, and it never reads or writes student or Data Center import data.
* **Roster size:** `AdminRoster::REQUIRED_COUNT` (hard-coded 3) is replaced by `config('comelec.admin_roster_size')` (default 3, no environment override). Three is an operational roster control only: it is not a schema constraint and not a maximum; the database allows any number of admin rows.
* **Migration impact (two additive migrations; the historical `create_admin_users_table` is untouched):** (1) adds nullable `authorized_email` with a unique index and makes `google_subject` nullable (existing unique index kept); (2) makes `authorized_email` NOT NULL. **Manual legacy-data prerequisite:** any `admin_users` row created before this correction has a subject but no email. Migration (2) will not invent or infer an email (for example from the subject), will not delete or replace the row and will not touch its subject: it fails safely, changes nothing, and requires the REAL authorized email to be assigned manually (for example `UPDATE admin_users SET authorized_email = '<real email>' WHERE id = <id>`), after which `php artisan migrate` is re-run. Rollback of (1) is refused while any admin has a NULL `google_subject` (subjects are never fabricated); rollback of (2) is always safe.
* **Phase 01B verification script** (`database/verification/phase1b_integrity_tests.sh`): the `admin_users` fixture now supplies `authorized_email` (fixture compatibility only; the invariant tested is unchanged). The script now distinguishes the Phase 01B baseline (must remain 23/23 ENUM columns with their CHECKs, meta-A/B/C) from approved Phase 02 extensions (`change_requests.status` ENUM and the CHECKs `chk_admin_users_role`, `chk_change_requests_status`, `chk_change_requests_decision_state`, `chk_change_requests_no_self_decision`; meta-A2/B2/C2). The baseline was NOT redefined to 24, no constraint was removed or weakened, an unapproved ENUM or CHECK still fails, and a dropped Phase 02 CHECK now fails too. `phase1b_enum_check_final.patch` is a historical artifact and is unchanged.
* **Tests:** admin tests revised to the final model (provisioning, first login, repeat login, first-link race, identity conflicts, isolation from Data Center imports, schema/cardinality, configured roster size, migration guards). See `TEST_PLAN.md`.
* **Documentation alignment:** `docs/REQUIREMENTS.md` now describes the current operational roster of three authorized COMELEC IT administrators with identical role permissions (previously "Exactly three provisioned IT admins"); no other requirement was changed.
* **Not claimed:** the Phase 02 gate is not marked passed. Phase 01C is untouched. No administrator revocation/departure procedure is defined or implemented: because the bound subject is authoritative, changing `authorized_email` does not revoke an already-bound administrator.

## 2026-10-06 — Phase 03A Student Master / Data Center Import Core (implemented; pending review)

* **Reason:** implement the approved import pipeline (upload -> staging -> validation -> preview -> confirmation -> chunked background processing -> students + append-only enrollment history -> summary) as a secure domain core. **Approval authority:** project owner (Phase 03A brief). The historical Python/bulk-SQL uploader was used only to understand source shape and scale; it is not the design.
* **Implemented:** `ImportBatchService`, `BatchStateMachine`, `ImportValidator`, `ImportChunkProcessor`, `RowClassifier`, `PlacementRule`, `RowNormalizer`, `HeaderMapper`, `CanonicalValueIndex`, CSV/XLSX readers, `ImportBatch` model, queued `ProcessImportBatch` / `ValidateImportBatch` jobs. Behaviour and guarantees are in `DATA_IMPORT.md`.
* **Data contract:** `Code` is the permanent identity (string, never cast); `No.` and `Sex` are ignored (Sex is not added to the student domain). Year levels use one internal representation (`1st Year`...`4th Year`) mapped from configurable source tokens; unknown years are INVALID.
* **Institutional email (fail closed):** the official source has no institutional email and `students.institutional_email` is NOT NULL, so the importer never creates a student and never fabricates, guesses or overwrites an email. Unknown IDs are `NEEDS_EXCEPTION_REVIEW` (`NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL`). No new email rule was invented; provisioning of new students is an open decision.
* **Migration impact (one additive migration):** `import_batch_rows` gains nullable `source_row_number`, `normalized_json`, `issues_json`, `processed_at`, a UNIQUE `(import_batch_id, source_row_number)` and a processing index. No existing column, constraint or ENUM/CHECK changed (Phase 01B baseline unaffected; the Phase 01B SQL integrity script still passes against the migrated schema). Reversible.
* **Config:** new `comelec.import` block (disk, directory, extensions, size limits, chunk size, year labels, header aliases).
* **Concurrency finding:** real multi-process runs on MariaDB showed InnoDB deadlocks (1213) between concurrent chunk transactions on one batch. Fixed by locking the batch row first in each chunk transaction (+ bounded retry); data integrity held even before the fix. Regression-checked by `database/verification/phase03a_import_concurrency.php`.
* **Hardening:** `.xlsx` declared-uncompressed-size cap (zip-bomb guard); deterministic (sorted) classification summary across database engines.
* **Testing impact:** Pest suites under `tests/Unit/StudentImport` and `tests/Feature/Import`; see `TEST_PLAN.md`.
* **Not claimed:** no upload UI/route, no review/resolution workflow for `NEEDS_EXCEPTION_REVIEW` rows, no new-student provisioning, and no performance target (none approved). Verified on MariaDB 10.11.14 and SQLite; the **MariaDB 10.4.32 run is still required**. Phase 01C is untouched and remains in progress; no Phase gate is marked passed.

## 2026-10-07 — Phase 03A Targeted Hardening Revision (pending review)

* **Batch precedence no longer inferred from the auto-increment id.** The processor compared `students.last_import_batch_id` with the batch id to skip overwriting the placement "when a newer batch already owns it". A database identifier is not official chronology, and nothing approved defines one, so the comparison is removed rather than replaced by an invented rule (not `>=`, not `created_at`, not `confirmed_at`). The applied batch becomes the current placement; enrollment history stays append-only; `last_import_batch_id` names the producing batch; replay stays a no-op. **Behavioural consequence:** an older file processed after a newer one now overwrites the current placement (history preserved). Recorded in `OPEN_DECISIONS.md` with candidate rules; no policy was introduced. Regression tests prove ids are never treated as chronology.
* **Staged source file cleanup.** `stage()` now tracks the exact storage key it writes and deletes it if the surrounding database work fails (a rollback cannot remove a file). The original exception is always the one raised; a cleanup failure is logged once (disk and key only) and never makes staging look successful. Successful staging is unchanged.
* **Not changed:** schema/migrations, Phase 01C status, election/candidate/voting rules. **Not claimed:** MariaDB 10.4.32 verification (still required locally).

## 2026-10-10 — Phase 03A Hardening: verify the staged file checksum before parsing (pending review)

* **Change:** `ImportValidator` now hashes the local copy of the stored source file (SHA-256) and compares it with `import_batches.checksum` before parsing. A mismatch, or a batch with no recorded checksum, fails closed with the coarse code `CHECKSUM_MISMATCH` (`SourceFileException`), before the staging transaction starts: no rows are staged or deleted and no student or enrollment data is touched. The batch ends `FAILED` through the existing `ImportBatchService::validate()` path and the existing `import.batch.failed` audit event.
* **Not changed:** schema/migrations, import rules, Phase 01C, the concurrency verification script.
* **Tests:** four cases appended to `tests/Feature/Import/ImportValidationTest.php` (matching checksum, modified file, tampering with a previewed batch, missing checksum).
* **Not claimed:** this detects accidental or malicious change of the stored file after staging, not a compromised stage-time upload (the checksum is computed from the file received). Recovery of batches stuck in `VALIDATING`/`PROCESSING` is separate and unchanged.

## 2026-10-10 — Phase 03A Hardening: queue settings and recovery guards (pending review)

* **Queue:** the database queue `retry_after` default is now 960 s (was 90 s) in `config/queue.php`, documented in `.env.example`. The import jobs' 900 s timeouts are unchanged. With 90 s the queue could hand a still-running import to a second worker and, after the attempts ran out, fail it while it was still working.
* **`ValidateImportBatch::failed()`** (new) calls `ImportBatchService::failValidation()`, which fails only a batch still `VALIDATING` (`VALIDATION_ERROR`, existing `import.batch.failed` audit event, no row data) and ignores status races.
* **`ImportChunkProcessor::processNextChunk()`** now checks the locked batch's status before reading or applying rows and does nothing unless it is `PROCESSING`. One existing test (`replaying an already-applied chunk changes nothing`) now puts the batch back into `PROCESSING` first, because it replayed a chunk against a `COMPLETED` batch.
* **Not changed:** `ProcessImportBatch::$uniqueFor`, migrations, import rules, Phase 01C, UI. **Not added:** a stale-worker sweeper. **Not claimed:** behaviour of a real queue worker (kill, redelivery, unique-lock expiry) has not been exercised; a manual MariaDB run with `QUEUE_CONNECTION=database` is still outstanding.

## 2026-10-11 — Phase 03B: Student Master / Data Center import UI
* **Added:** the administrator page `admin.data-center` (`/admin/data-center`, Volt) for the Phase 03A import: upload CSV/XLSX with academic year and semester, stage via `ImportBatchService::stage()` (uploader from the admin guard), queue `ValidateImportBatch`, list batches, inspect classification totals and a server-side paginated normalized-row preview, confirm via `confirm()` + `startProcessing()` (with an acknowledgement when rows will not be applied), show processing/completion/failure, and offer only the recoveries `BatchStateMachine` allows. The sidebar item "Student Master / Data Center" now has a real route and active state. Details: `DATA_IMPORT.md` (Phase 03B admin UI).
* **Revised (v2):** (1) `awaitingSince` lifecycle: state fingerprint captured before dispatch, so a synchronous/fast job cannot leave the page polling; 120 s cap; poll element no longer `.visible`-gated. (2) `ValidateImportBatch` now implements `ShouldBeUnique` (per batch id, `uniqueFor` 1000 s) — the only change to that job — because a button-disabled state cannot stop repeated actions or two administrator sessions queuing duplicate validations. (3) Term help text now says surrounding spaces are trimmed. No migrations, schema, voting or Phase 01C changes.
* **Security/privacy:** `raw_row_json` is never selected; failures show fixed coarse text from the existing `import.batch.failed` audit event, never exception text; every action re-authorizes the admin guard and re-checks the state machine.
* **Not changed:** migrations, database schema, `ImportBatchService`, `BatchStateMachine`, `ProcessImportBatch` and its processing behavior, models, `config/comelec.php`, authentication/authorization, Phase 01C, and voting/election logic. `ValidateImportBatch` changed only to add per-batch `ShouldBeUnique` protection. No packages added.
* **Not claimed:** exception-row resolution, new-student provisioning, any academic-year/semester policy, batch precedence, or recovery of a lost `PROCESSING` job (all remain open decisions). Feature review is still pending.

## 2026-10-12 — Student Masterlist (read-only) and Student Import rename
* **Added:** the administrator page `admin.students` (`/admin/students`, Volt): a read-only, server-paginated (25 per page) list of the existing `students` table with search by student ID or name, filters for college, course, year level and status, and a details modal (identity, institutional email, current placement, status, the import that last loaded the student and its recorded placements). Separate sidebar link "Student Masterlist".
* **Renamed (visible text only):** the "Student Master / Data Center" sidebar label, page title and page heading are now **"Student Import"**. Its URL (`/admin/data-center`), route name and behaviour are unchanged.
* **Privacy:** explicit column selects; `google_subject`, raw import rows and file checksums are never read; component state holds ids only; the search text is not placed in the URL. Viewing students is not audited (no existing page audits reads) — recorded as a possible later decision, not invented here.
* **CSS:** the existing import-modal backdrop blur selector in `resources/css/app.css` is extended to the student modal; the blur itself is unchanged.
* **Not changed:** migrations, schema, models, import logic, authentication, election and voting logic. No student write action exists.

## 2026-10-13 — Phase 03B post-merge clarification: new-student provisioning
- **Implementation clarification:** The merged Phase 03B implementation can create eligible new student records during confirmed batch processing. Eligible IDs are exactly 7 numeric digits (legacy) or 10 numeric digits (current), provided the row has no blocking validation or exception-review issue.
- **Email convention:** New-student institutional emails are currently generated as `<institutional_id>@student.buksu.edu.ph`. This remains a provisional development convention, not confirmed institutional policy. The university and responsible Google Workspace authority must confirm the required format and domain before staging or production use.
- **Identity protection:** New records begin with `google_subject = NULL`. Existing students' institutional IDs, institutional emails, and Google subjects are not overwritten by the importer. Student import remains separate from administrator provisioning.
- **Processing integrity:** Student creation, enrollment history, processed-row marking, and batch counters are handled within the chunk transaction. Staging and validation do not create student records.
- **Documentation alignment:** `DATA_IMPORT.md`, `STUDENT_MANAGEMENT.md`, `OPEN_DECISIONS.md`, and `TEST_PLAN.md` are being aligned with the merged implementation. This entry records current behavior; it does not constitute institutional approval of the generated email convention.
- **Still open:** Institutional confirmation of the email convention, exception-review resolution policy, authoritative batch precedence, and recovery of a lost processing job remain outstanding.

## Phase 04A: Election draft management (revised after review; pending review)
* **Added:** `/admin/elections` (all elections, state filter, 15 per page), `/admin/elections/new` (name, description, election type, optional starting template) and `/admin/elections/{election}` (read-only draft workspace: saved details and configuration status only). The create page is named `new` because the `RouteProtectionTest` guard flags admin routes matching "create"; the guard was left unchanged and its allow-list now includes the show route.
* **Schema (additive, all nullable, one migration):** `elections.description` (text), `elections.template_key` (string 100) and `elections.creation_key` (UUID, unique). `type` keeps its documented meaning (descriptive election kind from `config('comelec.election_types')`); the template is stored separately so the two are never conflated, and `config_json` is not used for drafts. Scope and academic period are **not** columns (see `OPEN_DECISIONS.md`).
* **Creation (one transaction):** `ElectionDraftService::createDraft()` inserts the election (`DRAFT`, `created_by`, `creation_key`), version 1 (`DRAFT`, `config_json` NULL), links `current_config_version_id` and writes `election.draft_created` (type, template key, version number; no name or description).
* **Duplicate submissions:** durable. The unique `creation_key` is written in the same transaction as the election, so a cache flush or a lost race cannot create a second draft: the losing transaction rolls back completely and the caller receives the winner. A key is replayed only to the administrator who created it. The page also disables the button while loading and ignores later clicks.
* **Authorization:** `admin` middleware plus `ElectionPolicy` (`viewAny`, `view`, `create`; admin on an intact roster).
* **Theme:** scoped `.theme-2k26` class on the three election pages only.
* **Not changed:** import, authentication, eligibility, contests, candidates, voting, results.

## Phase 04B: Election scope configuration (Revision 2; pending review)
* **Added:** `/admin/elections/{election}/scope` - an election's scope is its representation groups: named sets of administrator-configured dimensions (`scope_definition_json` = dimension key -> list of text values). Any authorized administrator can read it in any election state; creating, editing and deleting groups is offered only while the election is an editable draft. The election workspace links to it ("Configure scope" / "View scope") and shows the group count instead of "Not configured yet".
* **No schema change, no migration, no dependency.** The existing `representation_groups` table and the composite foreign key from `contests` are used as they are. No configuration version is created or changed by a scope edit.
* **Editing rule (decision, see `OPEN_DECISIONS.md`):** DRAFT only (`Election::SCOPE_EDITABLE_STATES`), with no locked configuration version and a current version that is a DRAFT with NULL `config_json`. `ElectionPolicy::manageScope` checks it for the UI; `ElectionScopeService` re-checks it on the rows it has locked (lock order: election, configuration version, group).
* **Service and authorization:** `ElectionScopeService` (create/update/delete in one transaction, audit inside it), `ElectionPolicy::viewScope` / `manageScope`. Every Livewire action re-authorizes; the election id, the editing group id and the messages are `#[Locked]`; every group query is scoped by `election_id`. Expected failures (invalid input, duplicate name, group in use, election no longer editable, group already gone) become messages, not error pages.
* **Audit:** `AuditLogger::record()` gains a trailing optional `?int $electionId` that fills the `audit_logs.election_id` column (existing call sites are unaffected). `election.scope_group_created|updated|deleted` and, corrected in this change, `election.draft_created` now set it. Scope metadata holds ids and counts only, never dimension keys or values.
* **Group names** are unique within an election, case-insensitively, enforced in the service under the election row lock (no database index, so no migration).
* **Not changed:** contests, voter/candidate eligibility, candidates, ballots, results, import, authentication, the 04A draft flow and its tests (updated only where the workspace text, the election-page list and the audit signature changed).
* **Not claimed:** what any dimension means or how it is matched (an eligibility-phase decision), versioned scope for exceptional changes, editing in `READY`. See `OPEN_DECISIONS.md` (Election scope configuration).
