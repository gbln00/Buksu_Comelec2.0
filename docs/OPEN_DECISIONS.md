# OPEN DECISIONS

These items must not be hard-coded until officially confirmed.

## November 2026
- Exact final list of contests/positions.
- Exact SSC seat count and selection limits.
- Exact 1st-Year Representative voter-to-contest mapping, if any special exception applies.
- Sector definitions and voter eligibility.
- Final ballot/abstention rules per contest where not yet confirmed.

## Institutional procedures
- Exact tie-resolution documentation/approval format after the COMELEC President/heads decide.
- Exact retention period for raw ballots, participation, audit logs, incidents, backups, and historical archives.
- Exact two-admin/two-approval thresholds for exceptional high-risk operations.
- Exact election recovery/resume procedure after catastrophic outage.
- RPO/RTO targets.
- Minimum-cell-size suppression threshold for detailed reporting, especially small sector/college/year combinations.
- Standard denominator policy for contest abstention percentages and candidate vote percentages:
  eligible contest voters vs. participating/voting contest voters.
- Whether student parties are persistent institutionally-recognized entities across elections or remain election-scoped.
- Student Google Workspace login matching: **decided for Phase 02** as automatic first-link by exact institutional email (verified email + configured domain + existing student + null `google_subject`), then by Google `sub`. The real institutional domain and Workspace configuration still need institutional confirmation before staging/production.

## Authentication & approval (Phase 02)

* Real change-request action types and approval thresholds (the registry ships empty; see the high-risk approval item above).
* General Reports (a Phase 03 concern) are distinct from the Phase 02 Login / Access Reports and are not implemented; their categories, statuses and workflow are undecided. Report status/handling for Access Issue reports is likewise not yet defined.
* No admin replacement/departure procedure is defined or implemented; none has been invented.

### Resolved Phase 02 authentication decision — administrator identity lifecycle

Administrator accounts are pre-authorized independently of the Data Center student import. Administrators do not publicly register or create their own administrator accounts.

The current initial roster consists of three operationally authorized administrators. This is an operational roster decision, not a permanent database or application cardinality limit.

Each administrator has a pre-authorized personal Google email before first login. `admin_users.google_subject` may be `NULL` before first successful login and is unique when populated.

On first Google login, the verified Google email is matched to the existing pre-authorized administrator record. When the match succeeds and `google_subject` is `NULL`, the stable Google `sub` is bound to that existing record. Subsequent administrator authentication uses the bound stable Google `sub`.

The Data Center import process is for student master data and must never create, discover, or provision administrator accounts.


## Scale targets
- Expected total voters.
- Peak concurrent voters.
- Peak live-result viewers.
- Target vote submissions/second.
- Target live result latency.
- Target recovery time.

When a decision is approved, update this document, the relevant domain document, `CHANGE_LOG.md`, and tests.

## Data Center import (Phase 03A)

* **New-student email convention and institutional approval.** The current Phase 03B implementation creates otherwise-valid new student records during confirmed batch processing when the institutional ID is exactly 7 numeric digits (legacy) or 10 numeric digits (current). It generates the institutional email as `<institutional_id>@student.buksu.edu.ph`. This is a provisional development convention, not confirmed institutional policy. The university and responsible Google Workspace authority must confirm the required email format and domain before staging or production use. If the convention is not approved, the provisioning behavior must be reviewed and changed before deployment. Unsupported IDs remain in `NEEDS_EXCEPTION_REVIEW`; the appropriate resolution policy for those records remains undecided.
* **Review/resolution of `NEEDS_EXCEPTION_REVIEW` rows** (unsupported unknown student IDs and case-only college/course inconsistencies): the classification and reasons are stored for preview, but who resolves them, how, and whether resolved rows are then applied is not defined. Eligible unknown students with supported 7-digit legacy or 10-digit current numeric IDs are classified `NEW` when no blocking validation or exception-review issue exists; they are not part of this exception-resolution decision.
* **Authoritative spelling of colleges/courses** (e.g. `CON` vs `cON`): currently decided per import against stored master values, then the file. Whether an approved college/course list should be the authority is undecided.
* **Academic year / semester per batch** are recorded as supplied; their allowed values and whether they gate an import are undecided.
* **Import performance targets** (no approved threshold; a 7,966-row synthetic import is exercised and its timing reported, nothing asserted).
* **Which official batch is "newer" (batch precedence).** The approved documents say the latest official upload is authoritative but do not define *latest*. `import_batches.id` is an identifier and `confirmed_at`/`completed_at` record when an administrator acted, not how recent the data is (an old file confirmed late would win), so none of them is used and none has been invented as a rule. Today the batch processed last owns a student's current placement; history stays append-only and `last_import_batch_id` names the owner. Risk: an older file processed after a newer one would overwrite the current placement (detectable from the history; nothing is lost). Candidate rules for the institution to choose from: a required official effective date or term per batch (e.g. validated `academic_year` + `semester` ordering), or refusing a batch whose term is older than one already applied.
* **Import UI: exception rows (Phase 03B).** The admin page shows `NEEDS_EXCEPTION_REVIEW` rows read-only with their reasons and states that they are not applied. It offers no resolve action and no new-student provisioning because no policy exists; the two decisions above must be made first.
* **Import UI: upload size.** The application import limit is 20 MiB (`comelec.import.max_file_bytes`), and Livewire's temporary-upload validation is explicitly configured to `['required', 'file', 'max:20480']`, matching that limit. The local PHP CLI reports `upload_max_filesize=40M` and `post_max_size=40M`. The effective limits of the web-serving PHP SAPI and any reverse proxy must still be verified in the deployment environment. XLSX expanded-content safeguards remain separate.
* **Import UI: lost jobs.** A `PROCESSING` batch whose job was lost has no supported recovery transition (the state machine only resumes `FAILED`); the page shows it as `PROCESSING` and keeps polling. See the Phase 03A queue hardening note in `CHANGE_LOG.md`.

## Election draft management (Phase 04A)
* **Template contents.** No approved document defines what a template pre-fills, so none is invented: `template_key` records which starting point was chosen (provenance only) and applies no contests, rules or eligibility. The UI says so. What each template configures is undecided.
* **Election type list.** The type options (University-wide, College, Department, Year level, Sectoral, Special) follow the election kinds in `REQUIREMENTS.md` and live in `config('comelec.election_types')`; COMELEC should confirm the list.
* **Scope.** Configured in Phase 04B as representation groups (see Election scope configuration below); it is not stored on `elections`.
* **Academic period.** No approved document defines an election academic period (academic year/semester exist only on import batches, free text, allowed values undecided), so it is not collected. If COMELEC requires it, the minimal change is nullable `academic_year` and `semester` columns on `elections` once the allowed values are decided.
* **Election name uniqueness.** No rule; two elections may share a name. Duplicate *submissions* are prevented, deliberate duplicates are not.
* **Idempotency limits.** The guarantee is the unique `creation_key` (the form's UUID). A replay with changed input returns the original draft unchanged; keys are kept for the life of the election; a key is unique across admins but replayed only to its creator. Verified on sqlite only (not MariaDB 10.4.32); true parallel requests are not exercised by the suite, the lost-race interleaving is simulated.
* **Draft changes.** No edit, delete or archive of a draft yet.
* **Schedule and timezone.** Not collected; timezone is the column default (`Asia/Manila`).
* **Theme.** The 2K26 palette is applied only to the election pages; the rest of the admin shell keeps the provisional palette until the planned redesign.

## Election scope configuration (Phase 04B)
* **Editable states: DRAFT only (settled project decision for Phase 04B).** `DOMAIN_MODEL_PROPOSAL.md` (section 10) says the normalized configuration tables are editable during `DRAFT` and `READY`. Phase 04B allows `DRAFT` only because nothing yet defines the `DRAFT` -> `READY` transition, what readiness validation covers, or whether an edit made in `READY` must send the election back to `DRAFT`. `READY` is read-only on the scope page. Widening it is a one-line change to `Election::SCOPE_EDITABLE_STATES`, but it needs a separate approved decision together with that transition workflow.
* **Still open (not decided by Phase 04B).** (1) Future eligibility rules and calculations: what dimension keys and values mean, whether matching is case-sensitive and how several dimensions combine. Scope organizes contests and decides nobody's eligibility. (2) The future lifecycle decision on `READY` editing and readiness re-validation.
* **`scope_definition_json` contract (proposed, not approved).** An object of dimension key -> non-empty list of text values. Keys: lowercase letters, digits, underscores, starting with a letter, at most 80 characters; at most 20 dimensions per group, 100 values per dimension, 255 characters per value or name; whitespace trimmed, control characters refused, exact-match de-duplication. No dimension key, value, college, course, year level or sector is shipped or preselected. What a key or value means, whether values match case-insensitively, and how several dimensions combine (all of them, any of them) are NOT defined: the eligibility phase must decide before it reads this JSON.
* **Scope is not versioned.** `representation_groups` has no `election_config_version_id`, while `DOMAIN_MODEL_PROPOSAL.md` describes a new generation of normalized rows per configuration version. Editing only in `DRAFT` (while the one current version is also a DRAFT) keeps this safe today. The exceptional-change workflow (a new version after lock) will need a decision, and probably a migration, before scope can be changed after lock.
* **Group names unique per election.** Enforced in the service under the election row lock, case-insensitively (ASCII case-folding is guaranteed on every database; non-ASCII folding follows the database). A database unique index would need a migration and is not added. Drop the rule if COMELEC wants duplicate names.
* **Concurrent edits.** Scope writes for an election serialize on the election row lock, but two administrators editing the same group are last-write-wins (no stale-edit warning). Any future code that changes an election's state must lock the election row first, or this check is not race-free.
* **Audit `election_id`.** Filled for `election.draft_created` and the scope events. Other families that concern an election (for example change requests once election actions exist) must pass it when they are built; today none does.
* **Group deletion.** Refused while a contest uses the group (application check plus the database foreign key). There is no archive or soft delete.
