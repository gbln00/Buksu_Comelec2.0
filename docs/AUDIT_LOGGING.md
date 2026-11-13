# AUDIT & LOGGING

## Purpose
Record who did what, when, to which election/object, and the outcome, without exposing voter selections.

## Suggested audit fields
`id, event_type, severity, actor_type, actor_id, election_id, target_type, target_id, description, metadata_json, correlation_id, created_at` plus IP/user-agent only if institutionally approved.

## Event families
Authentication, student changes/imports, candidate changes, party assignment, election lifecycle, approval actions, voting operational events, security events, backups/exports/restores, reports, incidents.

## Election-scoped events
An event that belongs to an election sets the dedicated `election_id` column (the logger's trailing `electionId` argument), not only metadata. Implemented so far: `election.draft_created` (Phase 04A) and `election.scope_group_created`, `election.scope_group_updated`, `election.scope_group_deleted` (Phase 04B). Their metadata holds ids and counts only; dimension keys, dimension values, election names and descriptions are never logged.

## Voting events
Examples: submission started, rejected, committed, duplicate attempt, idempotent replay, invalid ballot, ineligible attempt, reconciliation anomaly. Do not include candidate selections.

## Tamper evidence
Consider append-only storage and hash chaining/checkpoints for audit events. The mechanism is detection-oriented, not a replacement for access control.

## Access
All three IT admins may review logs according to role permissions. Access to sensitive logs is itself logged. Normal UI should not allow arbitrary deletion/editing.
