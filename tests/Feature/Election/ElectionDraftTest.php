<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
use App\Services\Election\ElectionDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/*
 * Phase 04A: election draft creation, listing and the read-only draft workspace. Everything is synthetic data.
 * Covers authorization, the transactional creation of the election and its initial config version, the separation of
 * election type from template, durable duplicate-submission protection, validation, the list and its filter, and the
 * workspace. No contests, eligibility, candidates or voting exist yet.
 */

beforeEach(function () {
    $this->admin = makeAdminRoster()->first();
});

function drafts(): ElectionDraftService
{
    return app(ElectionDraftService::class);
}

/** @return array{0: Election, 1: bool} */
function newDraft(string $name = '2K26 Student Council Election', string $type = 'UNIVERSITY_WIDE', ?string $template = null, ?string $key = null, ?AdminUser $admin = null, ?string $description = null): array
{
    return drafts()->createDraft($admin ?? test()->admin, $name, $description, $type, $template, $key ?? (string) Str::uuid());
}

function newPage()
{
    test()->actingAs(test()->admin, 'admin');

    return Volt::test('admin.elections.new');
}

function listPage()
{
    test()->actingAs(test()->admin, 'admin');

    return Volt::test('admin.elections');
}

/** An election in another lifecycle state, written the way a later phase would (tests may seed state directly). */
function electionIn(string $state, string $name = 'Seeded election'): int
{
    return DB::table('elections')->insertGetId([
        'name' => $name, 'type' => 'COLLEGE', 'state' => $state, 'created_by' => test()->admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------------------------- authorization

test('only an administrator can open the election pages', function () {
    [$election] = newDraft();
    $urls = [route('admin.elections'), route('admin.elections.new'), route('admin.elections.show', $election->id)];

    foreach ($urls as $url) {
        auth('admin')->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->actingAs(Student::factory()->linked()->create(), 'student');
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
    auth('student')->logout();

    $this->actingAs($this->admin, 'admin');
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
});

test('the Livewire components refuse a request without an administrator', function () {
    [$election] = newDraft();

    Volt::test('admin.elections.new')->assertForbidden();
    Volt::test('admin.elections')->assertForbidden();
    Volt::test('admin.elections.show', ['election' => $election])->assertForbidden();
});

test('a student can never create or view an election, and neither can an admin on a broken roster', function () {
    [$election] = newDraft();
    $student = Student::factory()->linked()->create();

    expect(Gate::forUser($student)->denies('create', Election::class))->toBeTrue()
        ->and(Gate::forUser($student)->denies('viewAny', Election::class))->toBeTrue()
        ->and(Gate::forUser($student)->denies('view', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $election))->toBeTrue();

    config(['comelec.admin_roster_size' => 4]); // the roster no longer matches the configured size, so it is not intact (no rows are deleted)
    expect(fn () => newDraft('Another'))->toThrow(AuthorizationException::class)
        ->and(Election::query()->count())->toBe(1);
});

test('an unknown or malformed election id is a 404', function () {
    $this->actingAs($this->admin, 'admin');

    $this->get(route('admin.elections.show', 987654))->assertNotFound();
    $this->get('/admin/elections/not-a-number')->assertNotFound();
});

// ---------------------------------------------------------------------------------------------- creation

test('creating a draft stores the election and its initial configuration version, linked, in the DRAFT state', function () {
    [$election, $created] = newDraft("  2K26   Student\tCouncil Election ", 'UNIVERSITY_WIDE', 'UNIVERSITY_WIDE', description: "Line one\r\nLine two  ");

    expect($created)->toBeTrue();

    $row = DB::table('elections')->where('id', $election->id)->first();
    expect($row->name)->toBe('2K26 Student Council Election') // trimmed, inner whitespace collapsed
        ->and($row->description)->toBe("Line one\nLine two")
        ->and($row->type)->toBe('UNIVERSITY_WIDE')
        ->and($row->template_key)->toBe('UNIVERSITY_WIDE')
        ->and($row->state)->toBe('DRAFT')
        ->and($row->created_by)->toBe($this->admin->id)
        ->and($row->approved_by)->toBeNull()
        ->and($row->locked_config_version_id)->toBeNull()
        ->and($row->opens_at)->toBeNull()->and($row->closes_at)->toBeNull()
        ->and($row->timezone)->toBe('Asia/Manila')
        ->and($row->creation_key)->toBeString();

    $versions = DB::table('election_config_versions')->where('election_id', $election->id)->get();
    expect($versions)->toHaveCount(1);
    $version = $versions->first();
    expect($version->version_number)->toBe(1)
        ->and($version->status)->toBe('DRAFT')
        ->and($version->config_json)->toBeNull() // only written when a version is snapshotted
        ->and($version->proposed_by)->toBe($this->admin->id)
        ->and($version->approved_by)->toBeNull()
        ->and($row->current_config_version_id)->toBe($version->id);

    $loaded = Election::query()->findOrFail($election->id);
    expect($loaded->currentConfigVersion->election_id)->toBe($loaded->id)
        ->and($loaded->creator->is($this->admin))->toBeTrue();
});

test('the election type and the starting template are separate choices and neither stands in for the other', function () {
    foreach (ElectionDraftService::templateKeys() as $template) {
        [$election] = newDraft("From {$template}", 'COLLEGE', $template);
        $row = $election->fresh();
        expect($row->type)->toBe('COLLEGE')->and($row->template_key)->toBe($template);
    }

    [$custom] = newDraft('From scratch', 'SPECIAL', null); // "Customize from scratch" = no template
    expect($custom->fresh()->type)->toBe('SPECIAL')->and($custom->fresh()->template_key)->toBeNull();

    foreach (ElectionDraftService::typeKeys() as $type) {
        [$election] = newDraft("Type {$type}", $type);
        expect($election->fresh()->type)->toBe($type)->and($election->fresh()->template_key)->toBeNull();
    }

    expect(ElectionDraftService::typeKeys())->toBe(['UNIVERSITY_WIDE', 'COLLEGE', 'DEPARTMENT', 'YEAR_LEVEL', 'SECTORAL', 'SPECIAL'])
        ->and(ElectionDraftService::templateKeys())->toBe(['UNIVERSITY_WIDE', 'COLLEGE_DEPARTMENT', 'YEAR_REPRESENTATIVE', 'SECTORAL']);
});

test('a template carries no configuration yet: creating a draft adds nothing but the election and version 1', function () {
    newDraft('Draft', 'SECTORAL', 'SECTORAL');

    expect(DB::table('elections')->count())->toBe(1)->and(DB::table('election_config_versions')->count())->toBe(1);
    foreach (['contests', 'contest_rules', 'contest_eligibility_rules', 'parties', 'candidates', 'representation_groups'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
});

test('invalid input is rejected before anything is written', function (string $name, ?string $description, string $type, ?string $template, string $key) {
    expect(fn () => drafts()->createDraft($this->admin, $name, $description, $type, $template, $key))->toThrow(InvalidArgumentException::class);

    expect(Election::query()->count())->toBe(0)->and(ElectionConfigVersion::query()->count())->toBe(0);
})->with([
    'empty name' => ['   ', null, 'COLLEGE', null, '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a11'],
    'name too long' => [str_repeat('a', 256), null, 'COLLEGE', null, '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a12'],
    'description too long' => ['Draft', str_repeat('a', 2001), 'COLLEGE', null, '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a13'],
    'unknown type' => ['Draft', null, 'MADE_UP', null, '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a14'],
    'a template key is not a type' => ['Draft', null, 'COLLEGE_DEPARTMENT', null, '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a15'],
    'unknown template' => ['Draft', null, 'COLLEGE', 'MADE_UP', '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a16'],
    'CUSTOM is not a template' => ['Draft', null, 'COLLEGE', 'CUSTOM', '3d7f2d9e-6f5e-4c51-8d0a-2b6f6f1c2a17'],
    'bad submission key' => ['Draft', null, 'COLLEGE', null, 'not-a-uuid'],
]);

test('a blank description is stored as no description', function () {
    [$election] = newDraft('Draft', 'COLLEGE', description: "  \n ");

    expect($election->fresh()->description)->toBeNull();
});

test('the database refuses an election that points at another election\'s configuration version', function () {
    [$a] = newDraft('Election A');
    [$b] = newDraft('Election B');
    $versionOfB = DB::table('election_config_versions')->where('election_id', $b->id)->value('id');

    expect(fn () => DB::table('elections')->where('id', $a->id)->update(['current_config_version_id' => $versionOfB]))
        ->toThrow(QueryException::class);
});

test('a failure while creating leaves no half-created election and the same form can be retried', function () {
    $failing = new class extends AuditLogger
    {
        public function record(string $eventType, string $severity = self::INFO, ?string $actorType = null, int|string|null $actorId = null, ?string $targetType = null, int|string|null $targetId = null, ?string $description = null, array $metadata = [], ?string $correlationId = null, ?int $electionId = null): void
        {
            throw new RuntimeException('audit failed');
        }
    };
    $key = (string) Str::uuid();

    expect(fn () => (new ElectionDraftService($failing))->createDraft($this->admin, 'Draft', null, 'COLLEGE', null, $key))->toThrow(RuntimeException::class);
    expect(Election::query()->count())->toBe(0)->and(ElectionConfigVersion::query()->count())->toBe(0);

    [$election, $created] = drafts()->createDraft($this->admin, 'Draft', null, 'COLLEGE', null, $key); // the same form, now working
    expect($created)->toBeTrue()->and(Election::query()->count())->toBe(1);
});

test('creation is audited without the election name or description', function () {
    [$election] = newDraft('Secret Name Election', 'SECTORAL', 'SECTORAL', description: 'Secret description');

    $event = DB::table('audit_logs')->where('event_type', 'election.draft_created')->first();
    expect($event)->not->toBeNull()
        ->and($event->actor_type)->toBe('ADMIN')->and($event->actor_id)->toBe((string) $this->admin->id)
        ->and($event->target_type)->toBe('election')->and($event->target_id)->toBe((string) $election->id)
        ->and($event->election_id)->toBe($election->id)
        ->and(json_decode($event->metadata_json, true))->toBe(['election_type' => 'SECTORAL', 'template' => 'SECTORAL', 'config_version_number' => 1])
        ->and(json_encode($event))->not->toContain('Secret Name Election')->not->toContain('Secret description');
});

// ---------------------------------------------------------------------------------------------- duplicate submissions

test('submitting the same form twice creates one election and returns the first one', function () {
    $key = (string) Str::uuid();

    [$first, $firstCreated] = newDraft('Draft', 'COLLEGE', null, $key);
    [$second, $secondCreated] = newDraft('Draft', 'COLLEGE', null, $key);
    [$third] = newDraft('Draft (edited retry)', 'SPECIAL', 'SECTORAL', strtoupper($key)); // changed input, upper-case key: still the first

    expect($firstCreated)->toBeTrue()->and($secondCreated)->toBeFalse()
        ->and($second->id)->toBe($first->id)->and($third->id)->toBe($first->id)
        ->and(Election::query()->count())->toBe(1)->and(ElectionConfigVersion::query()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'election.draft_created')->count())->toBe(1)
        ->and($first->fresh()->name)->toBe('Draft'); // the replay did not rewrite it
});

test('the guarantee is durable: it survives a cache flush, because the key is stored with the election', function () {
    $key = (string) Str::uuid();
    [$first] = newDraft('Draft', 'COLLEGE', null, $key);

    Cache::flush();
    [$again, $created] = newDraft('Draft', 'COLLEGE', null, $key);

    expect($created)->toBeFalse()->and($again->id)->toBe($first->id)->and(Election::query()->count())->toBe(1);
});

test('the database itself rejects a second election with the same creation key', function () {
    [$election] = newDraft();
    $key = DB::table('elections')->where('id', $election->id)->value('creation_key');

    expect(fn () => DB::table('elections')->insert([
        'name' => 'Duplicate', 'type' => 'COLLEGE', 'state' => 'DRAFT', 'created_by' => $this->admin->id,
        'creation_key' => $key, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Elections without a key (created any other way) never collide with each other.
    electionIn('DRAFT', 'No key one');
    electionIn('DRAFT', 'No key two');
    expect(DB::table('elections')->whereNull('creation_key')->count())->toBe(2);
});

test('two requests that both pass the existence check cannot both create: the loser rolls back and gets the winner', function () {
    $key = (string) Str::uuid();
    [$winner] = newDraft('Winner', 'COLLEGE', null, $key); // the first request has committed

    // The second request ran its "does this key exist?" check BEFORE the first one committed, so it saw nothing.
    $blind = new class(app(AuditLogger::class)) extends ElectionDraftService
    {
        protected function findByKey(string $submissionKey): ?Election
        {
            static $first = true;
            if ($first) {
                $first = false;

                return null;
            }

            return parent::findByKey($submissionKey);
        }
    };

    [$resolved, $created] = $blind->createDraft($this->admin, 'Loser', null, 'SPECIAL', null, $key);

    expect($created)->toBeFalse()->and($resolved->id)->toBe($winner->id)
        ->and(Election::query()->count())->toBe(1)               // nothing from the losing attempt survived
        ->and(ElectionConfigVersion::query()->count())->toBe(1)  // its version 1 was rolled back with it
        ->and(DB::table('audit_logs')->where('event_type', 'election.draft_created')->count())->toBe(1)
        ->and($winner->fresh()->name)->toBe('Winner');
});

test('a submission key is only replayed to the administrator who used it', function () {
    $other = makeAdminRoster()->last();
    $key = (string) Str::uuid();
    newDraft('Draft', 'COLLEGE', null, $key);

    expect(fn () => newDraft('Draft', 'COLLEGE', null, $key, $other))->toThrow(InvalidArgumentException::class)
        ->and(Election::query()->count())->toBe(1);
});

test('a different form creates a different election, even with the same name', function () {
    newDraft('Same name');
    newDraft('Same name');

    expect(Election::query()->count())->toBe(2);
});

test('the page ignores a second click on the same form, and the key cannot be changed from the browser', function () {
    $component = newPage()->set('name', 'Draft')->set('type', 'COLLEGE')->call('save');
    $component->assertRedirect(route('admin.elections.show', ['election' => Election::query()->value('id')]));

    $component->call('save')->call('save');
    expect(Election::query()->count())->toBe(1);

    expect(fn () => newPage()->set('submissionKey', (string) Str::uuid()))->toThrow(Exception::class, 'Cannot update locked property');
    expect(fn () => newPage()->set('submitted', true))->toThrow(Exception::class, 'Cannot update locked property');
});

test('the create button is disabled while the request runs', function () {
    $html = newPage()->html();

    expect($html)->toContain('wire:submit="save"')->toContain('wire:loading.attr="disabled"');
});

// ---------------------------------------------------------------------------------------------- the create page

test('the create page asks for the basic information and states that templates apply no configuration', function () {
    $component = newPage()->assertSet('type', '')->assertSet('template', '')->assertSet('name', '');
    $html = $component->html();

    foreach (['Election name', 'Description', 'Election type', 'Starting point', 'Customize from scratch'] as $text) {
        expect($html)->toContain($text);
    }
    foreach (['University-wide', 'College', 'Department', 'Year level', 'Sectoral', 'Special'] as $type) {
        expect($html)->toContain($type);
    }
    foreach (['University-Wide', 'College / Department', 'Year Representative'] as $template) {
        expect($html)->toContain(e($template));
    }
    expect($html)->toContain('applies no configuration')->toContain('theme-2k26');
});

test('saving stores the type and the template separately and opens the new draft workspace', function () {
    $component = newPage()
        ->set('name', '  2K26 Council ')->set('description', ' About this election ')
        ->set('type', 'COLLEGE')->set('template', 'YEAR_REPRESENTATIVE')->call('save');

    $election = Election::query()->firstOrFail();
    $component->assertHasNoErrors()->assertRedirect(route('admin.elections.show', ['election' => $election->id]));

    expect($election->name)->toBe('2K26 Council')->and($election->description)->toBe('About this election')
        ->and($election->type)->toBe('COLLEGE')->and($election->template_key)->toBe('YEAR_REPRESENTATIVE')
        ->and($election->state)->toBe('DRAFT')->and($election->created_by)->toBe($this->admin->id);
    expect(session('election_status'))->toContain('2K26 Council');
});

test('"Customize from scratch" stores no template', function () {
    newPage()->set('name', 'Draft')->set('type', 'SPECIAL')->set('template', '')->call('save')->assertHasNoErrors();

    expect(Election::query()->firstOrFail()->template_key)->toBeNull();
});

test('the form validates the name, description, type and starting point', function () {
    newPage()->set('name', '')->set('type', 'COLLEGE')->call('save')->assertHasErrors(['name' => 'required']);
    newPage()->set('name', str_repeat('a', 256))->set('type', 'COLLEGE')->call('save')->assertHasErrors(['name' => 'max']);
    newPage()->set('name', "   \t ")->set('type', 'COLLEGE')->call('save')->assertHasErrors(['name']);
    newPage()->set('name', 'Draft')->set('description', str_repeat('a', 2001))->set('type', 'COLLEGE')->call('save')->assertHasErrors(['description' => 'max']);
    newPage()->set('name', 'Draft')->set('type', '')->call('save')->assertHasErrors(['type' => 'required']);
    newPage()->set('name', 'Draft')->set('type', 'MADE_UP')->call('save')->assertHasErrors(['type']);
    newPage()->set('name', 'Draft')->set('type', 'COLLEGE_DEPARTMENT')->call('save')->assertHasErrors(['type']); // a template key is not a type
    newPage()->set('name', 'Draft')->set('type', 'COLLEGE')->set('template', 'MADE_UP')->call('save')->assertHasErrors(['template']);

    expect(Election::query()->count())->toBe(0);
});

test('a creation failure is reported, nothing is saved, and the form can be submitted again', function () {
    app()->bind(ElectionDraftService::class, fn () => new class extends ElectionDraftService
    {
        public function __construct() {}

        public function createDraft(AdminUser $admin, string $name, ?string $description, string $type, ?string $template, string $submissionKey): array
        {
            throw new RuntimeException('database down');
        }
    });

    $component = newPage()->set('name', 'Draft')->set('type', 'COLLEGE')->call('save');

    expect($component->get('problem'))->toContain('could not be created')->and($component->get('submitted'))->toBeFalse()
        ->and($component->html())->not->toContain('database down');
    expect(Election::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------- the draft workspace

test('the workspace shows the saved details, the template provenance and the configuration status', function () {
    [$election] = newDraft('2K26 Student Council', 'COLLEGE', 'YEAR_REPRESENTATIVE', description: "First line\nSecond line");

    $this->actingAs($this->admin, 'admin');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();

    expect($html)->toContain('2K26 Student Council')->toContain('First line')->toContain('Second line')
        ->toContain('College')                              // the election type
        ->toContain('Year Representative')                  // the template, shown separately
        ->toContain('no template configuration has been applied')
        ->toContain('No representation groups yet')          // scope summary
        ->toContain('Not scheduled yet')->toContain('Asia/Manila')
        ->toContain('Version 1')->toContain('None yet')->toContain('not snapshotted')
        ->toContain($this->admin->display_name)->toContain('theme-2k26')
        ->toContain('read-only')->toContain(route('admin.elections'));
});

test('the workspace says so when no template was chosen', function () {
    [$election] = newDraft('Scratch', 'SPECIAL');

    $this->actingAs($this->admin, 'admin');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();

    expect($html)->toContain('Customize from scratch')->toContain('No template was selected')->not->toContain('no template configuration has been applied');
});

test('the workspace is read-only and exposes no personal data', function () {
    [$election] = newDraft();

    $this->actingAs($this->admin, 'admin');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();

    expect($html)->not->toContain('<form')->not->toContain($this->admin->authorized_email)->not->toContain('google_subject')->not->toContain('creation_key')
        ->not->toMatch('/wire:(click|submit)=/');
});

test('the workspace opens an election in any lifecycle state', function () {
    $id = electionIn('OPEN');

    $this->actingAs($this->admin, 'admin')->get(route('admin.elections.show', $id))->assertOk()->assertSee('Seeded election');
});

// ---------------------------------------------------------------------------------------------- the list

test('the list is titled for what it shows, opens every election, and shows the election type', function () {
    $this->actingAs($this->admin, 'admin');
    expect(Volt::test('admin.elections')->html())->toContain('No elections yet')->toContain('New election draft');

    [$older] = newDraft('Older election', 'UNIVERSITY_WIDE', 'UNIVERSITY_WIDE');
    $this->travel(1)->minutes();
    [$newer] = newDraft('Newer election', 'COLLEGE');
    $openId = electionIn('OPEN', 'Running election');

    $html = Volt::test('admin.elections')->html();
    expect($html)->toContain('Older election')->toContain('Newer election')->toContain('Running election') // every state is listed
        ->toContain('University-wide')->toContain('Version 1')->toContain($this->admin->display_name)
        ->not->toContain('Election drafts') // the title no longer claims only drafts
        ->and(strpos($html, 'Newer election'))->toBeLessThan(strpos($html, 'Older election'))
        ->and($html)->toContain('theme-2k26');

    foreach ([$older->id, $newer->id, $openId] as $id) {
        expect($html)->toContain('href="'.route('admin.elections.show', $id).'"');
    }
});

test('every saved draft in the list can be reopened', function () {
    [$election] = newDraft('Reopen me', 'DEPARTMENT');

    $this->actingAs($this->admin, 'admin');
    $html = Volt::test('admin.elections')->html();
    preg_match('/href="([^"]*elections\/'.$election->id.')"/', $html, $m);

    expect($m[1] ?? null)->not->toBeNull();
    $this->get($m[1])->assertOk()->assertSee('Reopen me');
});

test('the state filter and the list query agree', function () {
    [$draft] = newDraft('A draft');
    electionIn('OPEN', 'Open one');
    electionIn('CLOSED', 'Closed one');

    $names = fn ($component) => collect($component->instance()->elections->items())->pluck('name')->sort()->values()->all();

    expect($names(listPage()))->toBe(['A draft', 'Closed one', 'Open one'])
        ->and($names(listPage()->set('state', 'DRAFT')))->toBe(['A draft'])
        ->and($names(listPage()->set('state', 'OPEN')))->toBe(['Open one'])
        ->and(listPage()->set('state', 'SCHEDULED')->html())->toContain('No elections in this state')->toContain('Show all states');

    $filtered = listPage()->set('state', 'OPEN')->call('clearFilter');
    expect($filtered->get('state'))->toBe('')->and($names($filtered))->toHaveCount(3);
});

test('an unknown state filter is dropped, and the filter can be deep-linked', function () {
    newDraft('A draft');
    electionIn('OPEN', 'Open one');

    listPage()->set('state', 'NOT_A_STATE')->assertSet('state', '');

    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.elections', ['state' => 'OPEN']))->assertOk()->getContent();
    expect($html)->toContain('Open one')->not->toContain('A draft');

    $this->get(route('admin.elections', ['state' => 'bogus']))->assertOk()->assertSee('A draft')->assertSee('Open one');
});

test('the filter options are the approved lifecycle states', function () {
    expect(Election::STATES)->toBe(['DRAFT', 'READY', 'APPROVED', 'SNAPSHOTTED', 'LOCKED', 'SCHEDULED', 'OPEN', 'PAUSED', 'CLOSED', 'RECONCILING', 'RESULTS_PENDING_VALIDATION', 'VALIDATED', 'OFFICIALLY_ANNOUNCED', 'FINALIZED']);

    $html = listPage()->html();
    foreach (Election::STATES as $state) {
        expect($html)->toContain('value="'.$state.'"');
    }
});

test('the list is paginated at 15 per page and changing the filter returns to the first page', function () {
    $this->actingAs($this->admin, 'admin');
    foreach (range(1, 20) as $n) {
        newDraft(sprintf('Election %02d', $n));
    }
    electionIn('OPEN', 'Open one');

    $component = Volt::test('admin.elections');
    expect($component->html())->toContain('Page 1 of 2')->toContain('Showing 1–15 of 21');
    expect(collect($component->instance()->elections->items()))->toHaveCount(15);

    $component->call('nextPage');
    expect(collect($component->instance()->elections->items()))->toHaveCount(6)->and($component->html())->toContain('Page 2 of 2');

    $component->set('state', 'DRAFT');
    expect($component->html())->toContain('Page 1 of 2')->and(collect($component->instance()->elections->items()))->toHaveCount(15);
});

test('the list is read-only and exposes no personal data', function () {
    newDraft('Draft');

    $html = listPage()->html();
    expect($html)->not->toContain($this->admin->authorized_email)->not->toContain('google_subject')->not->toContain('creation_key')
        ->not->toMatch('/wire:click="(delete|edit|lock|approve|update)/i');
});

// ---------------------------------------------------------------------------------------------- navigation, theme, scope

test('the sidebar links Elections and marks it current on every election page', function () {
    [$election] = newDraft();

    foreach ([route('admin.elections'), route('admin.elections.new'), route('admin.elections.show', $election->id), route('admin.elections.scope', $election->id)] as $url) {
        $html = $this->actingAs($this->admin, 'admin')->get($url)->assertOk()->getContent();

        expect($html)->toContain('href="'.route('admin.elections').'"')
            ->and(substr_count($html, 'aria-current="page"'))->toBe(2); // desktop + mobile drawer
    }
});

test('the 2K26 palette is scoped to the election pages and the rest of the admin theme is untouched', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.theme-2k26 {');
    $block = substr($css, strpos($css, '.theme-2k26 {'));
    foreach (['#380041', '#210027', '#f9b00f', '#ffffff', '#0c0c0c'] as $hex) {
        expect(strtolower($block))->toContain($hex);
    }
    expect($css)->toContain('--color-civic-gold: #d9a83f;'); // the provisional admin palette is unchanged

    $users = collect(glob(resource_path('views/livewire/admin/{,*/}*.blade.php'), GLOB_BRACE))
        ->filter(fn ($f) => str_contains(file_get_contents($f), 'theme-2k26'))->map(fn ($f) => basename(dirname($f)).'/'.basename($f))->sort()->values()->all();
    expect($users)->toBe(['admin/elections.blade.php', 'elections/new.blade.php', 'elections/scope.blade.php', 'elections/show.blade.php']);
});

test('the election pages write only through the service and add no other write path', function () {
    foreach (['elections.blade.php', 'elections/new.blade.php', 'elections/show.blade.php', 'elections/scope.blade.php'] as $file) {
        $source = file_get_contents(resource_path('views/livewire/admin/'.$file));

        expect($source)->not->toMatch('/->(update|insert|insertGetId|delete|upsert|save|create|forceDelete|truncate)\(/')
            ->not->toMatch('/DB::(insert|update|delete|statement)/');
    }

    expect(file_get_contents(resource_path('views/livewire/admin/elections/new.blade.php')))->toContain('ElectionDraftService');
});
