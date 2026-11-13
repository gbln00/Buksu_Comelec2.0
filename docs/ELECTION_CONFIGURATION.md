# ELECTION CONFIGURATION

## Creation workflow
Create Election → Basic Information → Template (optional) → Scope → Contests → Voter Eligibility → Candidate Eligibility → Candidates/Parties → Ballot Rules → Abstention → Schedule → Results/Reporting → Preview → Eligibility Test → Validation → Approval → Configuration Snapshot → Lock → Schedule/Open.

## Templates
The four supported templates are University-Wide, College / Department, Year Representative and Sectoral, plus a future “Create from Previous Election”. “Customize from scratch” is not a template: it stores `NULL` in `template_key`. A template is a starting configuration only, and template contents are not yet defined.

## Phase 04A: draft creation
An administrator creates a draft from `/admin/elections/new` with a name, optional description, an election type and an optional starting template ("Customize from scratch" stores none). One transaction creates the election in `DRAFT`, version 1 (`DRAFT`, `config_json` NULL) and links `current_config_version_id`. Type (what kind of election) and template (which starting point) are separate fields; a template pre-fills nothing yet (see `OPEN_DECISIONS.md`). `/admin/elections/{election}` reopens a draft read-only, showing saved details and configuration status. Scope, contests, eligibility, candidates, ballot rules and the schedule are later steps.

## Phase 04B: scope (representation groups)
The Scope step of the creation workflow is realized as representation groups on `/admin/elections/{election}/scope`. A group has a name and a `scope_definition_json` of administrator-configured dimensions (key -> list of values); the application supplies no dimensions or values. Groups organize contests; they are not voter or candidate eligibility rules, which are separate, later steps. Scope can be read in any state and edited only while the election is an editable draft (state `DRAFT`, nothing locked, current configuration version a `DRAFT` with no frozen JSON); editing in `READY` is an open decision. A group used by a contest cannot be deleted.

## State machine
DRAFT → READY → APPROVED → SNAPSHOTTED → LOCKED → SCHEDULED → OPEN ↔ PAUSED → CLOSED → RECONCILING → RESULTS_PENDING_VALIDATION → VALIDATED → OFFICIALLY_ANNOUNCED → FINALIZED.

## Locking
Before OPEN, ballot-critical configuration is editable through controlled workflow. Once locked, normal edits are disabled. Exceptional changes require a change request, reason, independent approval, new version/snapshot, and revalidation as applicable.

## Election clock
Backend/server time is authoritative. Frontend countdown is display-only. Timezone: Asia/Manila unless institutionally changed and documented.

## Historical record
Every finalized election keeps its final configuration snapshot and immutable results as historical data.
