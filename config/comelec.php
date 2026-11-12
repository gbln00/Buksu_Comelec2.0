<?php

/*
 * BUKSU COMELEC 2.0 — application-level identity and approval configuration.
 *
 * Nothing in this file is a secret except where noted, and nothing institution-
 * specific is hard-coded: values come from the environment.
 */
return [

    /*
     * Institutional Google Workspace domain that student accounts must belong
     * to (e.g. "students.example.edu"). Compared case-insensitively against the
     * domain part of the verified Google email. If empty/unset, EVERY student
     * login fails closed.
     */
    'student_email_domain' => env('COMELEC_STUDENT_EMAIL_DOMAIN'),

    /*
     * The current operational size of the authorized COMELEC IT administrator roster.
     * This is an OPERATIONAL control, not a schema limit or a permanent maximum: the
     * database allows any number of admin rows, and admin access is granted only while
     * the roster matches this value (see App\Services\Auth\AdminRoster).
     */
    'admin_roster_size' => 3,

    /*
     * The pre-authorized administrator records, provisioned OUTSIDE the application UI
     * via `php artisan comelec:provision-admins`. Administrators are never created by
     * Google login or by Data Center imports.
     *
     * JSON array with `admin_roster_size` objects:
     *   [{"authorized_email":"…","display_name":"…"}, … ]
     * `authorized_email` is the administrator's pre-authorized personal Google email.
     * Google's stable `sub` is NOT configured here: it is bound to the record at the
     * administrator's first verified Google login.
     * Personal identifiers: keep this in the deployment environment only.
     */
    'admin_identities' => json_decode((string) env('COMELEC_ADMIN_IDENTITIES', '[]'), true) ?: [],

    /*
     * Login / Access Report "problem type" choices: what the STUDENT says they need help with.
     * KEY => human-readable label. Config-backed (not a DB enum) so the list can evolve without
     * a schema migration; only the KEY is stored. An empty list rejects every submission.
     * Separate from the system-generated `denial_reason` (why authentication was denied).
     */
    'access_issue_problem_types' => [
        'STUDENT_RECORD_NOT_FOUND' => 'My student record was not found',
        'DATA_CENTER_INFORMATION_OUTDATED' => 'My university (Data Center) information is outdated',
        'LATE_VERIFIED_COR' => 'My COR was verified late',
        'RETURNEE_RETURNING_STUDENT' => 'I am a returnee / returning student',
        'RECENTLY_ENROLLED_OR_NEWLY_ADMITTED' => 'I recently enrolled or was newly admitted',
        'TRANSFER_OR_COURSE_CHANGE' => 'I transferred or changed my course',
        'INCORRECT_COLLEGE_COURSE_OR_YEAR' => 'My college, course, or year level is incorrect',
        'INCORRECT_STUDENT_STATUS' => 'My student status is incorrect',
        'GOOGLE_OR_LOGIN_PROBLEM' => 'I have a Google account or login problem',
        'OTHER' => 'Other',
    ],

    /*
     * Registered change-request action types. Intentionally EMPTY: Phase 02
     * ships the generic approval mechanism only. Later phases register their
     * real action types here; no institutional action types are invented.
     */
    'change_request_action_types' => [],

    /*
     * Phase 04A: the kind of election (`elections.type`), key => label. A descriptive label, never a code branch.
     * The kinds come from docs/REQUIREMENTS.md ("college, department, year-level, sectoral, special, and
     * university-wide elections"). It says nothing about who may vote or stand: that is the election's SCOPE,
     * a later configuration step (representation groups / eligibility rules), not a column on `elections`.
     */
    'election_types' => [
        'UNIVERSITY_WIDE' => 'University-wide',
        'COLLEGE' => 'College',
        'DEPARTMENT' => 'Department',
        'YEAR_LEVEL' => 'Year level',
        'SECTORAL' => 'Sectoral',
        'SPECIAL' => 'Special',
    ],

    /*
     * Phase 04A: optional starting templates (docs/ELECTION_CONFIGURATION.md), key => label, kept in
     * `elections.template_key` for provenance. A template is a starting point only and is DISTINCT from the election
     * type. No template contents (contests, eligibility, rules) are defined yet, so choosing one initializes nothing;
     * "Customize from scratch" is simply no template (NULL).
     */
    'election_templates' => [
        'UNIVERSITY_WIDE' => 'University-Wide',
        'COLLEGE_DEPARTMENT' => 'College / Department',
        'YEAR_REPRESENTATIVE' => 'Year Representative',
        'SECTORAL' => 'Sectoral',
    ],

    /*
     * Student master data / Data Center import (Phase 03A).
     *
     * Everything institution-shaped lives here, not in code. `year_levels` is the ONE internal
     * representation of a year level (the canonical label stored in students.current_year_level
     * and student_enrollments.year_level) mapped from the tokens the source may use. The FIRST
     * entry is the label assigned after a course change ("1st Year" rule). A source value that
     * matches no token is INVALID: nothing is guessed and nothing is derived from subjects.
     * `header_aliases` maps source column headers (lower-cased, whitespace-collapsed) to domain
     * fields. Source columns that map to no field (e.g. "No.", "Sex") are never used.
     */
    'import' => [
        'disk' => env('COMELEC_IMPORT_DISK', 'local'),
        'directory' => 'student-imports',
        'extensions' => ['csv', 'xlsx'],
        'max_file_bytes' => 20 * 1024 * 1024,
        // .xlsx only: cap on the DECLARED uncompressed size of the worksheet / shared-strings parts that are read
        // into memory (guards against a small archive that expands enormously). ~7,966 rows need a few MB.
        'max_uncompressed_part_bytes' => 100 * 1024 * 1024,
        'chunk_size' => (int) env('COMELEC_IMPORT_CHUNK_SIZE', 500),
        'year_levels' => [
            '1st Year' => ['1', '1st', '1st year', 'first year'],
            '2nd Year' => ['2', '2nd', '2nd year', 'second year'],
            '3rd Year' => ['3', '3rd', '3rd year', 'third year'],
            '4th Year' => ['4', '4th', '4th year', 'fourth year'],
        ],
        'header_aliases' => [
            'institutional_id' => ['code', 'student id'],
            'last_name' => ['last name'],
            'first_name' => ['first name'],
            'middle_name' => ['middle name'],
            'college' => ['colleges', 'college'],
            'course' => ['course'],
            'year_level' => ['year', 'year level'],
            'status' => ['status'],
        ],
    ],

];
