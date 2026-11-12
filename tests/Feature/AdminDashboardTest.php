<?php

use App\Models\Student;
use App\Services\Approval\ChangeRequestService;

/*
 * Admin dashboard shell (presentation only): layout, sidebar, header and real-data panels.
 * Auth/authorization behaviour is covered by the Auth tests; this file only checks what the page shows.
 */

beforeEach(function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$this->admin, $this->other] = makeAdminRoster();
});

function dashboardHtml($test): string
{
    return $test->actingAs($test->admin, 'admin')->get(route('admin.home'))->assertOk()->getContent();
}

test('the dashboard uses the admin shell, header identity and the existing logout action', function () {
    $html = dashboardHtml($this);

    expect($html)
        ->toContain('admin-shell')
        ->toContain($this->admin->display_name)
        ->toContain('action="'.route('logout').'"')
        ->toContain('Skip to content');
});

test('sidebar links only existing routes and shows every planned entry as unavailable', function () {
    $html = dashboardHtml($this);

    foreach ([route('admin.home'), route('admin.approvals'), route('admin.access-issues'), route('admin.data-center'), route('admin.students'), route('admin.elections')] as $href) {
        expect($html)->toContain('href="'.$href.'"');
    }

    foreach (['Student Import', 'Student Masterlist', 'Elections', 'Eligibility', 'Candidates', 'Results &amp; Analytics', 'Audit &amp; Incidents', 'Admin Logs', 'Backup &amp; Recovery', 'Operational Health'] as $label) {
        expect($html)->toContain($label);
    }

    // 7 planned pages, each rendered twice (desktop + mobile drawer) as non-link, aria-disabled entries.
    expect(substr_count($html, 'aria-disabled="true"'))->toBe(14)
        ->and($html)->toContain('aria-current="page"');
});

test('no invented routes: the planned pages are not linked anywhere on the dashboard', function () {
    preg_match_all('/href="([^"#]*)"/', dashboardHtml($this), $m);
    $internal = collect($m[1])->filter(fn ($h) => str_starts_with($h, url('/')))->unique()->values()->all();

    expect($internal)->each->toBeIn([
        url('/'), route('admin.home'), route('admin.approvals'), route('admin.access-issues'), route('admin.data-center'), route('admin.students'), route('admin.elections'),
    ]);
});

test('summary figures are real: zero state, then counts that follow the data', function () {
    $html = dashboardHtml($this);
    expect($html)->toContain('No pending approvals')->toContain('No new notifications.');

    Student::factory()->count(3)->create(['status' => 'ACTIVE']);
    Student::factory()->count(2)->create(['status' => 'INACTIVE']);
    $request = app(ChangeRequestService::class)->create($this->other, 'TEST_ONLY_ACTION', 'subject', '1');

    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.home'))->assertOk()->getContent();

    expect($html)
        ->toContain('Request #'.$request->id)
        ->toContain('Change request #'.$request->id)
        ->toContain('3 active · 2 inactive')
        ->not->toContain('No pending approvals');
});

test('the shared portal layout (student side) does not get the admin theme', function () {
    $student = Student::factory()->linked()->create();

    $html = $this->actingAs($student, 'student')->get(route('student.home'))->assertOk()->getContent();

    expect($html)->not->toContain('admin-shell')->not->toContain('Administration');
});
