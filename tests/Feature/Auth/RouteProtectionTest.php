<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStudent;
use App\Models\AdminUser;
use App\Models\Student;
use App\Services\Approval\ChangeRequestService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Volt\Volt;

test('guests are sent to the Google login page for every protected area', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['admin.home', 'admin.approvals', 'student.home']);

test('a student gets 403 on every admin route and stays logged in as a student', function (string $route) {
    $student = Student::factory()->linked()->create();

    $this->actingAs($student, 'student')->get(route($route))->assertForbidden();

    $this->assertAuthenticatedAs($student, 'student');
    $this->assertGuest('admin');
})->with(['admin.home', 'admin.approvals']);

test('an admin gets 403 on the student area', function () {
    $admin = makeAdminRoster()[0];

    $this->actingAs($admin, 'admin')->get(route('student.home'))->assertForbidden();
});

test('an admin is denied and logged out when the authorized roster is not intact', function () {
    $admins = makeAdminRoster();
    $this->actingAs($admins[0], 'admin');
    $admins[2]->delete();

    $this->get(route('admin.home'))->assertForbidden();

    $this->assertGuest('admin');
    expect(DB::table('audit_logs')->where('event_type', 'auth.admin_access_denied')->exists())->toBeTrue();
});

test('an admin with the wrong role cannot enter the admin area', function () {
    $admins = makeAdminRoster();
    $tamper = fn () => DB::table('admin_users')->where('id', $admins[0]->id)->update(['role' => 'SUPER_ADMIN']);

    if (dbEnforcesChecks()) {
        expect($tamper)->toThrow(QueryException::class); // CHECK refuses it

        return;
    }

    $tamper();
    $this->actingAs($admins[0]->fresh(), 'admin')->get(route('admin.home'))->assertForbidden();
});

test('admin pages render for an authorized admin', function () {
    $admin = makeAdminRoster()[0];

    $this->actingAs($admin, 'admin')->get(route('admin.home'))->assertOk();
    $this->actingAs($admin, 'admin')->get(route('admin.approvals'))->assertOk();
});

test('the admin area exposes no way to create, delete, promote or demote admins', function () {
    $adminUris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn ($uri) => str_starts_with($uri, 'admin'))
        ->sort()->values()->all();

    // The complete, explicit admin surface. Anything new must be added here on purpose.
    expect($adminUris)->toBe(['admin', 'admin/access-issues', 'admin/approvals', 'admin/data-center', 'admin/elections', 'admin/elections/new', 'admin/elections/{election}', 'admin/students']);

    // No route anywhere mentions admin management.
    $all = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => strtolower($r->uri().' '.$r->getName()));
    expect($all->filter(fn ($r) => preg_match('/(admins?|admin_users?).*(create|store|destroy|delete|promote|demote|invite|register|edit|update)|(create|store|delete|promote|demote|invite).*admin/', $r))->all())->toBe([]);

    // No model-level path either: the admin model has no mass-assignable identity/role.
    expect((new AdminUser)->getFillable())->toBe(['display_name']);
});

test('every admin and student route carries the identity middleware', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = Route::gatherRouteMiddleware($route);
        if (str_starts_with($route->uri(), 'admin')) {
            expect($middleware)->toContain(EnsureAdmin::class);
        }
        if (str_starts_with($route->uri(), 'student')) {
            expect($middleware)->toContain(EnsureStudent::class);
        }
    }
});

/*
|--------------------------------------------------------------------------
| Livewire authorization boundary
|--------------------------------------------------------------------------
| Livewire 4 only re-applies middleware from an allow-list on the Livewire update
| requests (Mechanisms\PersistentMiddleware) and skips fake requests such as
| Livewire::test(). So the boundary is verified with real HTTP requests.
*/

test('EnsureAdmin and EnsureStudent are registered as Livewire persistent middleware', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(EnsureAdmin::class, EnsureStudent::class, Authenticate::class);
});

/** Loads an admin Livewire page as $admin and returns its raw snapshot + a poster for update calls. */
function adminApprovalsSnapshot(AdminUser $admin): string
{
    $html = test()->actingAs($admin, 'admin')->get(route('admin.approvals'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    expect($m)->not->toBeEmpty();

    return html_entity_decode($m[1], ENT_QUOTES);
}

function livewireCall(string $snapshot, string $method, array $params = [])
{
    return test()->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params, 'metadata' => (object) []]],
        ]],
    ], ['X-Livewire' => 'true']);
}

test('an authorized non-requesting admin can decide through the Livewire endpoint (positive control)', function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$a, $b] = makeAdminRoster();
    $request = app(ChangeRequestService::class)->create($a, 'TEST_ONLY_ACTION');

    $snapshot = adminApprovalsSnapshot($b);
    livewireCall($snapshot, 'approve', [$request->id])->assertOk();

    expect($request->fresh()->status)->toBe('APPROVED')
        ->and($request->fresh()->decided_by)->toBe($b->id);
});

test('a student session cannot drive an admin Livewire component', function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$a, $b] = makeAdminRoster();
    $request = app(ChangeRequestService::class)->create($a, 'TEST_ONLY_ACTION');
    $snapshot = adminApprovalsSnapshot($b);

    // Same component snapshot, but the session now belongs to a student.
    Auth::forgetGuards();
    $this->actingAs(Student::factory()->linked()->create(), 'student');

    livewireCall($snapshot, 'approve', [$request->id])->assertForbidden();

    expect($request->fresh()->status)->toBe('PENDING');
});

test('an unauthenticated request cannot drive an admin Livewire component', function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$a, $b] = makeAdminRoster();
    $request = app(ChangeRequestService::class)->create($a, 'TEST_ONLY_ACTION');
    $snapshot = adminApprovalsSnapshot($b);

    Auth::forgetGuards();

    $status = livewireCall($snapshot, 'approve', [$request->id])->getStatusCode();

    expect($status)->not->toBe(200);
    expect($request->fresh()->status)->toBe('PENDING');
});

test('the requester cannot approve their own request through the Livewire endpoint', function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$a] = makeAdminRoster();
    $request = app(ChangeRequestService::class)->create($a, 'TEST_ONLY_ACTION');
    $snapshot = adminApprovalsSnapshot($a);

    livewireCall($snapshot, 'approve', [$request->id])->assertForbidden();

    expect($request->fresh()->status)->toBe('PENDING');
    expect(DB::table('audit_logs')->where('event_type', 'change_request.self_decision_denied')->exists())->toBeTrue();
});

test('the component itself refuses to act without an admin identity (defence in depth)', function () {
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$a] = makeAdminRoster();
    $request = app(ChangeRequestService::class)->create($a, 'TEST_ONLY_ACTION');

    // Livewire::test() bypasses persistent middleware; the action must still refuse.
    $this->actingAs(Student::factory()->linked()->create(), 'student');

    Volt::test('admin.approvals')->call('approve', $request->id)->assertForbidden();

    expect($request->fresh()->status)->toBe('PENDING');
});

test('persistent middleware alone (no component-level check) blocks a student on an admin component update', function () {
    $admin = makeAdminRoster()[0];
    $html = $this->actingAs($admin, 'admin')->get(route('admin.home'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    $snapshot = html_entity_decode($m[1], ENT_QUOTES);

    Auth::forgetGuards();
    $this->actingAs(Student::factory()->linked()->create(), 'student');

    // admin.home has no action guard of its own: only EnsureAdmin (persistent) stands in the way.
    $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertForbidden();
});
