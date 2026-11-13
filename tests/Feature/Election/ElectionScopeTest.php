<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\RepresentationGroup;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
use App\Services\Election\ElectionDraftService;
use App\Services\Election\ElectionScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/*
 * Phase 04B: election scope = representation groups. All data is synthetic and every dimension key / value below is
 * an arbitrary test string - the application ships none. Groups organize contests; they are not eligibility rules.
 */

beforeEach(function () {
    $this->admin = makeAdminRoster()->first();
});

function scopeService(): ElectionScopeService
{
    return app(ElectionScopeService::class);
}

function scopeDraft(string $name = 'Scope test election'): Election
{
    return app(ElectionDraftService::class)->createDraft(test()->admin, $name, null, 'UNIVERSITY_WIDE', null, (string) Str::uuid())[0];
}

/** @param array<string, list<string>> $definition */
function scopeGroup(Election $election, string $name = 'Group A', array $definition = ['dimension_a' => ['one', 'two']]): RepresentationGroup
{
    return scopeService()->createGroup(test()->admin, $election, $name, $definition);
}

function scopePage(Election $election)
{
    test()->actingAs(test()->admin, 'admin');

    return Volt::test('admin.elections.scope', ['election' => $election]);
}

function scopeSetState(Election $election, string $state): void
{
    DB::table('elections')->where('id', $election->getKey())->update(['state' => $state]);
}

function scopeContest(Election $election, RepresentationGroup $group): int
{
    return DB::table('contests')->insertGetId([
        'election_id' => $election->getKey(),
        'election_config_version_id' => $election->fresh()->current_config_version_id,
        'name' => 'A contest', 'position_label' => 'A position', 'scope' => null,
        'representation_group_id' => $group->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function scopeAudit(string $event): Collection
{
    return DB::table('audit_logs')->where('event_type', $event)->get();
}

// ---------------------------------------------------------------------------------------------- authorization

test('only an administrator can open the scope page', function () {
    $election = scopeDraft();
    $url = route('admin.elections.scope', $election->getKey());

    auth('admin')->logout();
    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(Student::factory()->linked()->create(), 'student')->get($url)->assertForbidden();
    auth('student')->logout();

    $this->actingAs($this->admin, 'admin')->get($url)->assertOk();
    $this->get(route('admin.elections.scope', 987654))->assertNotFound();
    $this->get('/admin/elections/not-a-number/scope')->assertNotFound();
});

test('the Livewire component refuses a guest and a student session', function () {
    $election = scopeDraft();

    Volt::test('admin.elections.scope', ['election' => $election])->assertForbidden();

    $this->actingAs(Student::factory()->linked()->create(), 'student');
    Volt::test('admin.elections.scope', ['election' => $election])->assertForbidden();
});

test('every action re-checks the administrator: a session that is no longer an admin cannot write', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    $form = fn () => scopePage($election)->set('name', 'Another')->set('dimensions', [['key' => 'dimension_b', 'values' => 'x']]);
    [$saving, $editing, $deleting] = [$form(), $form(), $form()];

    auth('admin')->logout();

    $saving->call('save')->assertForbidden();
    $editing->call('editGroup', $group->getKey())->assertForbidden();
    $deleting->call('deleteGroup', $group->getKey())->assertForbidden();

    expect(RepresentationGroup::query()->count())->toBe(1)->and($group->fresh()->name)->toBe('Group A');
});

test('the scope policies: reading needs an administrator, changing also needs an editable draft', function () {
    $election = scopeDraft();
    $student = Student::factory()->linked()->create();

    expect(Gate::forUser($student)->denies('viewScope', $election))->toBeTrue()
        ->and(Gate::forUser($student)->denies('manageScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manageScope', $election))->toBeTrue();

    scopeSetState($election, 'READY');
    expect(Gate::forUser($this->admin)->allows('viewScope', $election->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->admin)->denies('manageScope', $election->fresh()))->toBeTrue();
});

test('each way an election stops being editable denies changes but not reading', function (Closure $break) {
    $election = scopeDraft();
    $break($election);
    $election = $election->fresh();

    expect(Gate::forUser($this->admin)->denies('manageScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewScope', $election))->toBeTrue()
        ->and($election->allowsScopeEditing($election->currentConfigVersion))->toBeFalse();
})->with([
    'a later lifecycle state' => [fn (Election $e) => scopeSetState($e, 'APPROVED')],
    'a locked configuration version is recorded' => [function (Election $e) {
        DB::table('elections')->where('id', $e->getKey())->update(['locked_config_version_id' => $e->current_config_version_id]);
    }],
    'the current version is no longer a draft' => [function (Election $e) {
        DB::table('election_config_versions')->where('id', $e->current_config_version_id)->update(['status' => 'APPROVED']);
    }],
    'the current version holds frozen JSON' => [function (Election $e) {
        DB::table('election_config_versions')->where('id', $e->current_config_version_id)->update(['config_json' => json_encode(['frozen' => true])]);
    }],
    'there is no current version' => [function (Election $e) {
        DB::table('elections')->where('id', $e->getKey())->update(['current_config_version_id' => null]);
    }],
]);

test('the editable-state decision: DRAFT only, READY is read-only until a later phase decides otherwise', function () {
    expect(Election::SCOPE_EDITABLE_STATES)->toBe(['DRAFT']);

    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeSetState($election, 'READY');

    expect(fn () => scopeService()->createGroup($this->admin, $election->fresh(), 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(fn () => scopeService()->updateGroup($this->admin, $election->fresh(), $group->getKey(), 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(fn () => scopeService()->deleteGroup($this->admin, $election->fresh(), $group->getKey()))->toThrow(AuthorizationException::class);

    expect(RepresentationGroup::query()->count())->toBe(1)->and($group->fresh()->name)->toBe('Group A');
});

test('the editability predicate refuses a missing version row and a version of another election', function () {
    $election = scopeDraft();
    $version = $election->currentConfigVersion;

    expect($election->allowsScopeEditing($version))->toBeTrue()
        ->and($election->allowsScopeEditing(null))->toBeFalse(); // current_config_version_id set, but no such row

    $foreign = new ElectionConfigVersion;
    $foreign->forceFill($version->getAttributes());
    $foreign->election_id = (int) $election->getKey() + 1000; // a version that belongs to another election
    expect($election->allowsScopeEditing($foreign))->toBeFalse();

    $other = new ElectionConfigVersion;
    $other->forceFill($version->getAttributes());
    $other->id = (int) $version->getKey() + 1000; // the right election, but not the CURRENT version
    expect($election->allowsScopeEditing($other))->toBeFalse();
});

test('a broken administrator roster cannot change scope', function () {
    $election = scopeDraft();
    config(['comelec.admin_roster_size' => 4]); // the roster no longer matches the configured size, so it is not intact

    expect(fn () => scopeService()->createGroup($this->admin, $election, 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(RepresentationGroup::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------- the service

test('create, update and delete are transactional, audited with the election id, and leak no scope values', function () {
    $election = scopeDraft();

    $group = scopeService()->createGroup($this->admin, $election, '  First   group ', [
        'Dimension_B' => [' beta one ', 'beta two', 'beta one'],
        'dimension_a' => ['7', 'alpha'], // numeric text is still text
    ]);

    expect($group->election_id)->toBe($election->id)
        ->and($group->name)->toBe('First group')
        ->and($group->scope_definition_json)->toBe(['dimension_a' => ['7', 'alpha'], 'dimension_b' => ['beta one', 'beta two']]);

    $updated = scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['axis_c' => ['gamma']]);
    expect($updated->name)->toBe('Renamed')->and($updated->scope_definition_json)->toBe(['axis_c' => ['gamma']]);

    scopeService()->deleteGroup($this->admin, $election, $group->getKey());
    expect(RepresentationGroup::query()->count())->toBe(0);

    foreach (['created', 'updated', 'deleted'] as $verb) {
        $rows = scopeAudit("election.scope_group_{$verb}");
        expect($rows)->toHaveCount(1);
        $row = $rows->first();

        expect($row->election_id)->toBe($election->id)                       // the dedicated column, not just metadata
            ->and($row->actor_type)->toBe('ADMIN')->and($row->actor_id)->toBe((string) $this->admin->id)
            ->and($row->target_type)->toBe('representation_group')->and($row->target_id)->toBe((string) $group->id);

        $everything = json_encode($row);
        foreach (['alpha', 'beta', 'gamma', 'dimension_a', 'dimension_b', 'axis_c', 'First group', 'Renamed'] as $secret) {
            expect($everything)->not->toContain($secret);
        }
    }

    expect(json_decode(scopeAudit('election.scope_group_created')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id, 'dimension_count' => 2, 'value_count' => 4])
        ->and(json_decode(scopeAudit('election.scope_group_updated')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id, 'dimension_count' => 1, 'value_count' => 1])
        ->and(json_decode(scopeAudit('election.scope_group_deleted')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id]);
});

test('a failure while auditing rolls the whole change back', function () {
    $election = scopeDraft();
    $existing = scopeGroup($election);
    $failing = new class extends AuditLogger
    {
        public function record(string $eventType, string $severity = self::INFO, ?string $actorType = null, int|string|null $actorId = null, ?string $targetType = null, int|string|null $targetId = null, ?string $description = null, array $metadata = [], ?string $correlationId = null, ?int $electionId = null): void
        {
            throw new RuntimeException('audit failed');
        }
    };
    $service = new ElectionScopeService($failing);

    expect(fn () => $service->createGroup($this->admin, $election, 'New', ['dimension_a' => ['x']]))->toThrow(RuntimeException::class)
        ->and(fn () => $service->updateGroup($this->admin, $election, $existing->getKey(), 'Changed', ['dimension_a' => ['y']]))->toThrow(RuntimeException::class)
        ->and(fn () => $service->deleteGroup($this->admin, $election, $existing->getKey()))->toThrow(RuntimeException::class);

    expect(RepresentationGroup::query()->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Group A')
        ->and($existing->fresh()->scope_definition_json)->toBe(['dimension_a' => ['one', 'two']]);
});

test('scope edits never create or change a configuration version', function () {
    $election = scopeDraft();
    $versionBefore = DB::table('election_config_versions')->where('election_id', $election->id)->first();

    $group = scopeGroup($election);
    scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['dimension_a' => ['x']]);
    scopeService()->deleteGroup($this->admin, $election, $group->getKey());

    expect(DB::table('election_config_versions')->where('election_id', $election->id)->get())->toHaveCount(1)
        ->and(DB::table('election_config_versions')->where('election_id', $election->id)->first())->toEqual($versionBefore)
        ->and($election->fresh()->current_config_version_id)->toBe($versionBefore->id);
});

test('invalid input is rejected before anything is written', function (string $name, array $definition) {
    $election = scopeDraft();

    expect(fn () => scopeService()->createGroup($this->admin, $election, $name, $definition))->toThrow(InvalidArgumentException::class);
    expect(RepresentationGroup::query()->count())->toBe(0)->and(DB::table('audit_logs')->where('event_type', 'like', 'election.scope_%')->count())->toBe(0);
})->with([
    'empty name' => ['   ', ['dimension_a' => ['x']]],
    'name too long' => [str_repeat('n', 256), ['dimension_a' => ['x']]],
    'control character in the name' => ["Bad\x07name", ['dimension_a' => ['x']]],
    'no dimensions' => ['Group', []],
    'too many dimensions' => ['Group', array_combine(array_map(fn ($i) => "dimension_{$i}", range(1, 21)), array_fill(0, 21, ['x']))],
    'key with a space' => ['Group', ['year level' => ['x']]],
    'key starting with a digit' => ['Group', ['1key' => ['x']]],
    'key with punctuation' => ['Group', ['a-b' => ['x']]],
    'key too long' => ['Group', [str_repeat('k', 81) => ['x']]],
    'numeric key' => ['Group', [5 => ['x']]],
    'the same key in two cases' => ['Group', ['dimension_a' => ['x'], 'Dimension_A' => ['y']]],
    'a dimension with no values' => ['Group', ['dimension_a' => []]],
    'a blank value' => ['Group', ['dimension_a' => ['  ']]],
    'values that are not a list' => ['Group', ['dimension_a' => ['k' => 'x']]],
    'values that are not text' => ['Group', ['dimension_a' => [1.5]]],
    'an integer value' => ['Group', ['dimension_a' => [5]]],
    'an integer among text values' => ['Group', ['dimension_a' => ['x', 7]]],
    'a boolean or null value' => ['Group', ['dimension_a' => [true, null]]],
    'a nested value' => ['Group', ['dimension_a' => [['x']]]],
    'a value that is too long' => ['Group', ['dimension_a' => [str_repeat('v', 256)]]],
    'too many values' => ['Group', ['dimension_a' => array_map(fn ($i) => "value {$i}", range(1, 101))]],
    'a control character in a value' => ['Group', ['dimension_a' => ["a\x00b"]]],
    'invalid UTF-8 in a value' => ['Group', ['dimension_a' => ["a\xC3\x28b"]]],
]);

test('the documented limits are accepted exactly at the boundary', function () {
    $election = scopeDraft();
    $definition = [];
    foreach (range(1, ElectionScopeService::MAX_DIMENSIONS) as $i) {
        $definition[sprintf('d%02d', $i)] = ['v'];
    }
    $definition['d01'] = array_map(fn ($i) => str_pad((string) $i, ElectionScopeService::MAX_DIMENSION_VALUE_LENGTH, 'x'), range(1, ElectionScopeService::MAX_VALUES_PER_DIMENSION));

    $group = scopeService()->createGroup($this->admin, $election, str_repeat('n', ElectionScopeService::MAX_GROUP_NAME_LENGTH), $definition);

    expect($group->scope_definition_json)->toHaveCount(20)->and($group->scope_definition_json['d01'])->toHaveCount(100);
});

test('non-breaking and other Unicode spaces around values are trimmed', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Group', ['dimension_a' => ["\u{00A0}spaced\u{2003}", 'plain']]);

    expect($group->scope_definition_json)->toBe(['dimension_a' => ['spaced', 'plain']]);
});

test('group names are unique within an election, ignoring case, and free again in another election', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first, 'Shared Name');
    $other = scopeGroup($first, 'Other name');

    expect(fn () => scopeGroup($first, 'shared name'))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_DUPLICATE_NAME)
        ->and(fn () => scopeService()->updateGroup($this->admin, $first, $other->getKey(), 'SHARED NAME', ['dimension_a' => ['x']]))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_DUPLICATE_NAME);

    scopeService()->updateGroup($this->admin, $first, $group->getKey(), 'SHARED name', ['dimension_a' => ['x']]); // its own name, new case
    scopeGroup($second, 'Shared Name');                                                                          // another election

    expect(RepresentationGroup::query()->count())->toBe(3);
});

test('a group cannot be read, updated or deleted through a different election', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first);

    expect(fn () => scopeService()->updateGroup($this->admin, $second, $group->getKey(), 'Hijack', ['dimension_a' => ['x']]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => scopeService()->deleteGroup($this->admin, $second, $group->getKey()))->toThrow(ModelNotFoundException::class);

    expect($group->fresh()->name)->toBe('Group A')->and($group->fresh()->election_id)->toBe($first->id);
});

test('a group assigned to a contest cannot be deleted', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeContest($election, $group);

    expect(fn () => scopeService()->deleteGroup($this->admin, $election, $group->getKey()))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_IN_USE);

    expect(RepresentationGroup::query()->whereKey($group->getKey())->exists())->toBeTrue()
        ->and(scopeAudit('election.scope_group_deleted'))->toHaveCount(0);
});

test('if a contest appears after the application check, the database still refuses and the user gets the same message', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeContest($election, $group);

    $racing = new class(app(AuditLogger::class)) extends ElectionScopeService
    {
        protected function isGroupAssigned(int $electionId, int $groupId): bool
        {
            return false; // the check "passed" just before the contest was committed
        }
    };

    expect(fn () => $racing->deleteGroup($this->admin, $election, $group->getKey()))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_IN_USE);

    expect(RepresentationGroup::query()->whereKey($group->getKey())->exists())->toBeTrue()
        ->and(scopeAudit('election.scope_group_deleted'))->toHaveCount(0);
});

test('the database refuses a contest that points at another election\'s group', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first);

    expect(fn () => scopeContest($second, $group))->toThrow(QueryException::class);
});

test('the audit logger stays backward compatible and fills election_id only when given', function () {
    $audit = app(AuditLogger::class);
    $election = scopeDraft();

    $audit->record('test.without_election', actorType: 'ADMIN', actorId: 1);
    $audit->record('test.with_election', actorType: 'ADMIN', actorId: 1, electionId: $election->id);

    expect(scopeAudit('test.without_election')->first()->election_id)->toBeNull()
        ->and(scopeAudit('test.with_election')->first()->election_id)->toBe($election->id)
        ->and(fn () => $audit->record('test.bad', metadata: ['candidate_id' => 1], electionId: $election->id))->toThrow(InvalidArgumentException::class);
});

test('on MySQL/MariaDB every scope write locks the election, its version and the group row', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);

    DB::enableQueryLog();
    scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['dimension_a' => ['x']]);
    $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains(strtolower($sql), 'for update'))->values();
    DB::disableQueryLog();

    expect($locking)->toHaveCount(3)
        ->and($locking[0])->toContain('`elections`')
        ->and($locking[1])->toContain('`election_config_versions`')
        ->and($locking[2])->toContain('`representation_groups`');
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Row locks (FOR UPDATE) are only emitted by MySQL/MariaDB; SQLite ignores them.');

// ---------------------------------------------------------------------------------------------- the scope page

test('the page renders its empty state and a blank form with nothing preselected', function () {
    $election = scopeDraft();

    $html = scopePage($election)->assertOk()->assertSee('No representation groups yet')->html();

    expect($html)->toContain('theme-2k26')->toContain('wire:submit="save"')->toContain('Create representation group')
        ->toContain('do not decide who may vote or stand')
        ->not->toContain('read-only')
        ->not->toMatch('/value="[^"]+"[^>]*wire:model="dimensions\.0\.key"/');

    $component = scopePage($election);
    expect($component->get('dimensions'))->toBe([['key' => '', 'values' => '']])->and($component->get('name'))->toBe('');
});

test('the form accepts the largest allowed dimension: 100 values of 255 characters each', function () {
    $election = scopeDraft();
    $values = array_map(fn (int $i) => str_pad(sprintf('v%03d-', $i), 255, 'x'), range(1, 100));
    expect(mb_strlen(implode("\n", $values)))->toBeGreaterThan(10000);

    scopePage($election)
        ->set('name', 'Largest group')
        ->set('dimensions', [['key' => 'dimension_a', 'values' => implode("\n", $values)]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Representation group created.');

    $stored = RepresentationGroup::query()->sole()->scope_definition_json['dimension_a'];
    expect($stored)->toHaveCount(100)->and($stored)->toBe($values)
        ->and(array_unique(array_map('mb_strlen', $stored)))->toBe([255]);
});

test('the page creates, edits and deletes a group and reports it', function () {
    $election = scopeDraft();

    $page = scopePage($election)
        ->set('name', 'Page group')
        ->set('dimensions', [['key' => '  Dimension_B ', 'values' => "beta one\nbeta two\n\nbeta one"], ['key' => 'dimension_a', 'values' => 'alpha']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Representation group created.')->assertSee('Page group')->assertSee('beta one, beta two');

    $group = RepresentationGroup::query()->sole();
    expect($group->scope_definition_json)->toBe(['dimension_a' => ['alpha'], 'dimension_b' => ['beta one', 'beta two']])
        ->and($page->get('name'))->toBe('')->and($page->get('editingGroupId'))->toBeNull()
        ->and(scopeAudit('election.scope_group_created')->first()->election_id)->toBe($election->id);

    $page->call('editGroup', $group->getKey())
        ->assertSet('name', 'Page group')->assertSet('editingGroupId', $group->getKey())
        ->assertSet('dimensions', [['key' => 'dimension_a', 'values' => 'alpha'], ['key' => 'dimension_b', 'values' => "beta one\nbeta two"]])
        ->assertSee('Edit representation group')
        ->set('name', 'Renamed group')->set('dimensions', [['key' => 'dimension_c', 'values' => 'gamma']])
        ->call('save')->assertHasNoErrors()->assertSee('Representation group updated.')->assertSee('Renamed group');

    expect($group->fresh()->scope_definition_json)->toBe(['dimension_c' => ['gamma']])
        ->and(scopeAudit('election.scope_group_updated')->first()->election_id)->toBe($election->id);

    $page->call('deleteGroup', $group->getKey())->assertSee('Representation group deleted.')->assertSee('No representation groups yet');
    expect(RepresentationGroup::query()->count())->toBe(0)
        ->and(scopeAudit('election.scope_group_deleted')->first()->election_id)->toBe($election->id);
});

test('the form explains validation problems in plain words', function () {
    $election = scopeDraft();

    scopePage($election)->set('name', '')->call('save')->assertHasErrors(['name' => 'required'])->assertSee('Enter a group name.');
    scopePage($election)->set('name', str_repeat('n', 256))->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save')->assertHasErrors(['name' => 'max']);
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'Bad Key!', 'values' => 'x']])->call('save')
        ->assertHasErrors(['dimensions.0.key' => 'regex'])->assertSee('Use lowercase letters, numbers and underscores');
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => '', 'values' => 'x']])->call('save')->assertHasErrors(['dimensions.0.key' => 'required']);
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => " \n "]])->call('save')->assertHasErrors(['dimensions.0.values' => 'required']);
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => str_repeat('v', 10001)]])->call('save')
        ->assertHasNoErrors() // no artificial form cap: the service enforces the per-value limit and explains it
        ->assertSee('Each scope value must contain 1 to 255 characters');

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('the same dimension key twice (in any case) is a validation error, not a server error', function () {
    $election = scopeDraft();

    scopePage($election)->set('name', 'G')
        ->set('dimensions', [['key' => 'dimension_a', 'values' => 'x'], ['key' => ' Dimension_A ', 'values' => 'y']])
        ->call('save')
        ->assertHasErrors(['dimensions.0.key' => 'distinct'])
        ->assertSee('Each dimension key can be used only once');

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('too many dimensions, and rows forged into the wrong shape, are handled', function () {
    $election = scopeDraft();

    $many = array_map(fn ($i) => ['key' => "dimension_{$i}", 'values' => 'x'], range(1, 21));
    scopePage($election)->set('name', 'G')->set('dimensions', $many)->call('save')->assertHasErrors(['dimensions' => 'max']);

    $page = scopePage($election)->set('name', 'G')->set('dimensions', ['nonsense', ['key' => ['array'], 'values' => 5], ['key' => 'dimension_a']]);
    $page->call('save')->assertHasErrors();

    $page = scopePage($election);
    foreach (range(1, 25) as $unused) {
        $page->call('addDimension');
    }
    expect(count($page->get('dimensions')))->toBe(ElectionScopeService::MAX_DIMENSIONS)->and($page->get('problem'))->toContain('at most 20');

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('service rules come back as messages: duplicate name, and deleting a group that a contest uses', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Taken');
    scopeContest($election, $group);

    $page = scopePage($election)->set('name', 'taken')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_DUPLICATE_NAME);
    $page->assertSee(ElectionScopeService::MSG_DUPLICATE_NAME);

    $page->call('deleteGroup', $group->getKey()); // the button is hidden for this group, but the action can still be called
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_IN_USE)->and(RepresentationGroup::query()->count())->toBe(1);

    $html = scopePage($election)->html();
    expect($html)->toContain('1 contest assigned')->not->toContain('wire:click="deleteGroup('.$group->id.')"');
});

test('the same form submitted twice creates one group', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('name', 'Once')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');
    $page->call('save'); // a queued second click: the form was already cleared by the first

    expect(RepresentationGroup::query()->count())->toBe(1);

    $page->set('name', 'once')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save'); // a re-typed copy
    expect(RepresentationGroup::query()->count())->toBe(1)->and($page->get('problem'))->toBe(ElectionScopeService::MSG_DUPLICATE_NAME);
});

test('ids and messages cannot be changed from the browser', function () {
    $election = scopeDraft();
    $page = scopePage($election);

    foreach (['electionId' => 1, 'editingGroupId' => 1, 'status' => 'x', 'problem' => 'x'] as $property => $value) {
        expect(fn () => $page->set($property, $value))->toThrow(Exception::class, 'Cannot update locked property');
    }
});

test('a forged group id from another election changes and reveals nothing', function () {
    $mine = scopeDraft('Mine');
    $theirs = scopeDraft('Theirs');
    $foreign = scopeGroup($theirs, 'Foreign group', ['secret_key' => ['secret value']]);

    $page = scopePage($mine)->call('editGroup', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($page->get('name'))->toBe('')->and($page->get('editingGroupId'))->toBeNull()
        ->and($page->html())->not->toContain('Foreign group')->not->toContain('secret value');

    $page->call('deleteGroup', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($foreign->fresh()->name)->toBe('Foreign group');
});

test('a lock timeout or deadlock asks the user to try again; any other database error is not hidden', function () {
    $election = scopeDraft();
    $failing = fn (int $code) => new class(app(AuditLogger::class), $code) extends ElectionScopeService
    {
        public function __construct(AuditLogger $audit, private readonly int $code)
        {
            parent::__construct($audit);
        }

        public function createGroup(AdminUser $admin, Election $election, string $name, array $scopeDefinition): RepresentationGroup
        {
            $pdo = new PDOException('simulated');
            $pdo->errorInfo = ['HY000', $this->code, 'simulated'];

            throw new QueryException('mariadb', 'insert into representation_groups', [], $pdo);
        }
    };

    foreach ([1205, 1213] as $code) {
        app()->bind(ElectionScopeService::class, fn () => $failing($code));
        $page = scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');

        expect($page->get('problem'))->toContain('someone else')->toContain('try again');
    }

    app()->bind(ElectionScopeService::class, fn () => $failing(1451));
    $page = scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']]);
    expect(fn () => $page->call('save'))->toThrow(QueryException::class);
});

test('deleting twice (a double click) is a message, not an error page', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);

    $page = scopePage($election)->call('deleteGroup', $group->getKey())->assertSee('Representation group deleted.');
    $page->call('deleteGroup', $group->getKey());

    expect($page->get('problem'))->toContain('no longer exists')->and(RepresentationGroup::query()->count())->toBe(0);
});

test('if the election leaves the draft state while the page is open, writes are refused with a message and the page turns read-only', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    $page = scopePage($election)->call('editGroup', $group->getKey());

    scopeSetState($election, 'READY'); // another administrator, or a later phase

    $page->set('name', 'Too late')->call('save');
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE)->and($page->get('editingGroupId'))->toBeNull();

    $page->call('deleteGroup', $group->getKey());
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE);
    $page->call('editGroup', $group->getKey());
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE);

    $page->assertSee('shown read-only')->assertSee('Group A')->assertDontSee('Create representation group')->assertDontSee('Delete');
    expect($group->fresh()->name)->toBe('Group A')->and(RepresentationGroup::query()->count())->toBe(1);
});

test('a non-draft election shows its scope read-only, with no form and no actions', function () {
    $election = scopeDraft();
    scopeGroup($election, 'Saved group', ['dimension_a' => ['one', 'two']]);
    scopeSetState($election, 'LOCKED');

    $this->actingAs($this->admin, 'admin');
    $html = $this->get(route('admin.elections.scope', $election->id))->assertOk()->getContent();

    expect($html)->toContain('Saved group')->toContain('one, two')->toContain('shown read-only')
        ->not->toContain('wire:submit')->not->toContain('Create representation group')
        ->not->toMatch('/wire:click="(editGroup|deleteGroup|addDimension)/');

    $empty = scopeDraft('Empty');
    scopeSetState($empty, 'LOCKED');
    $this->get(route('admin.elections.scope', $empty->id))->assertOk()->assertSee('No groups were configured while this election was a draft.');
});

test('the page exposes no personal data and uses valid icons', function () {
    $election = scopeDraft();
    scopeGroup($election);

    $html = scopePage($election)->html();
    expect($html)->not->toContain($this->admin->authorized_email)->not->toContain('google_subject')->not->toContain('creation_key');

    $icons = [];
    preg_match_all('/icon="([a-z0-9-]+)"/', file_get_contents(resource_path('views/livewire/admin/elections/scope.blade.php')), $icons);
    foreach (array_unique($icons[1]) as $icon) {
        expect(file_exists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$icon}.blade.php")) || file_exists(resource_path("views/flux/icon/{$icon}.blade.php")))->toBeTrue("icon [{$icon}] does not exist");
    }
});

// ---------------------------------------------------------------------------------------------- the workspace

test('the election workspace links to the scope page and shows the scope summary', function () {
    $election = scopeDraft();
    $this->actingAs($this->admin, 'admin');

    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('Configure scope')->toContain('href="'.route('admin.elections.scope', $election->id).'"')
        ->toContain('No representation groups yet')->not->toContain('Not configured yet');

    scopeGroup($election, 'One');
    scopeGroup($election, 'Two');
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('2 representation groups');

    scopeSetState($election, 'READY');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('View scope')->not->toContain('Configure scope')->toContain('href="'.route('admin.elections.scope', $election->id).'"');
});

test('the workspace button follows the policy: no editing links once a version is frozen', function () {
    $election = scopeDraft();
    DB::table('election_config_versions')->where('id', $election->current_config_version_id)->update(['status' => 'SNAPSHOTTED', 'config_json' => json_encode(['frozen' => true])]);

    $this->actingAs($this->admin, 'admin');
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('View scope')->not->toContain('Configure scope');
});

// ---------------------------------------------------------------------------------------------- scope of the change

test('the scope code only writes through the service and ships no institutional values', function () {
    $page = file_get_contents(resource_path('views/livewire/admin/elections/scope.blade.php'));

    expect($page)->toContain('ElectionScopeService')
        ->not->toMatch('/->(update|insert|insertGetId|delete|upsert|save|forceDelete|truncate|forceFill)\(/')
        ->not->toMatch('/DB::(insert|update|delete|statement)/');

    foreach ([
        app_path('Services/Election/ElectionScopeService.php'),
        app_path('Models/RepresentationGroup.php'),
        resource_path('views/livewire/admin/elections/scope.blade.php'),
    ] as $file) {
        expect(file_get_contents($file))->not->toMatch('/\b(college|course|program|department|sector|year[_ -]?level|1st year)\b/i', basename($file));
    }
});

test('no migration, dependency or ballot table is part of this change', function () {
    expect(glob(database_path('migrations/*04b*')))->toBe([])
        ->and(glob(database_path('migrations/*scope*')))->toBe([]);
});
