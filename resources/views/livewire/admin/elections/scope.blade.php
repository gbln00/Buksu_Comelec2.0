<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\RepresentationGroup;
use App\Services\Election\ElectionScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04B: an election's scope = its representation groups (named sets of administrator-configured dimensions).
 * Any authorized administrator can READ it in any election state; creating, editing and deleting groups is offered only
 * while the election is an editable draft (ElectionPolicy::manageScope), and every action re-checks that on the server.
 * Groups organize contests; they are NOT voter or candidate eligibility rules. Writes go only through
 * ElectionScopeService. Only ids are kept in component state, and the ids are #[Locked].
 */
new #[Layout('components.layouts.admin')] #[Title('Election scope')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    /** The group being edited (null = creating). Set only by editGroup(); the browser cannot change it. */
    #[Locked]
    public ?int $editingGroupId = null;

    /** Server-set messages. */
    #[Locked]
    public ?string $status = null;

    #[Locked]
    public ?string $problem = null;

    public string $name = '';

    /** @var array<int, array{key: string, values: string}> */
    public array $dimensions = [['key' => '', 'values' => '']];

    public function mount(Election $election): void
    {
        Gate::forUser($this->admin())->authorize('viewScope', $election);

        $this->electionId = (int) $election->getKey();
    }

    #[Computed]
    public function election(): Election
    {
        $election = $this->loadElection();
        Gate::forUser($this->admin())->authorize('viewScope', $election);

        return $election;
    }

    /** Whether the administrator may change scope right now (re-evaluated on every render). */
    #[Computed]
    public function canManage(): bool
    {
        return Gate::forUser($this->admin())->allows('manageScope', $this->election);
    }

    #[Computed]
    public function groups()
    {
        return RepresentationGroup::query()
            ->select(['representation_groups.id', 'representation_groups.name', 'representation_groups.scope_definition_json'])
            ->selectSub(
                DB::table('contests')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('contests.representation_group_id', 'representation_groups.id')
                    ->whereColumn('contests.election_id', 'representation_groups.election_id'),
                'contests_count',
            )
            ->where('representation_groups.election_id', $this->electionId)
            ->orderBy('representation_groups.name')
            ->orderBy('representation_groups.id')
            ->get();
    }

    public function addDimension(): void
    {
        if (count($this->dimensions) >= ElectionScopeService::MAX_DIMENSIONS) {
            $this->problem = __('A group can have at most :max dimensions.', ['max' => ElectionScopeService::MAX_DIMENSIONS]);

            return;
        }

        $this->dimensions[] = ['key' => '', 'values' => ''];
    }

    public function removeDimension(int $index): void
    {
        if (! array_key_exists($index, $this->dimensions)) {
            return;
        }

        unset($this->dimensions[$index]);
        $this->dimensions = array_values($this->dimensions);

        if ($this->dimensions === []) {
            $this->dimensions = [['key' => '', 'values' => '']];
        }
    }

    public function editGroup(int $groupId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($groupId): void {
            $election = $this->manageableElection();
            $group = RepresentationGroup::query()
                ->select(['id', 'name', 'scope_definition_json'])
                ->where('election_id', $election->getKey())
                ->whereKey($groupId)
                ->firstOrFail();

            $rows = [];
            foreach ((array) $group->scope_definition_json as $key => $values) {
                $rows[] = ['key' => (string) $key, 'values' => implode("\n", array_map('strval', (array) $values))];
            }

            $this->resetValidation();
            $this->name = (string) $group->name;
            $this->dimensions = $rows === [] ? [['key' => '', 'values' => '']] : $rows;
            $this->editingGroupId = (int) $group->getKey();
        });
    }

    public function save(): void
    {
        $this->problem = null;
        $this->status = null;

        $this->normalizeForm();
        $this->validate(
            [
                'name' => ['required', 'string', 'max:'.ElectionScopeService::MAX_GROUP_NAME_LENGTH],
                'dimensions' => ['required', 'array', 'min:1', 'max:'.ElectionScopeService::MAX_DIMENSIONS],
                'dimensions.*.key' => ['required', 'string', 'max:'.ElectionScopeService::MAX_DIMENSION_KEY_LENGTH, 'regex:/\A[a-z][a-z0-9_]*\z/', 'distinct'],
                'dimensions.*.values' => ['required', 'string'],
            ],
            [
                'name.required' => __('Enter a group name.'),
                'name.max' => __('The group name may have at most :max characters.', ['max' => ElectionScopeService::MAX_GROUP_NAME_LENGTH]),
                'dimensions.max' => __('A group can have at most :max dimensions.', ['max' => ElectionScopeService::MAX_DIMENSIONS]),
                'dimensions.*.key.required' => __('Enter a dimension key.'),
                'dimensions.*.key.max' => __('A dimension key may have at most :max characters.', ['max' => ElectionScopeService::MAX_DIMENSION_KEY_LENGTH]),
                'dimensions.*.key.regex' => __('Use lowercase letters, numbers and underscores, starting with a letter.'),
                'dimensions.*.key.distinct' => __('Each dimension key can be used only once in a group.'),
                'dimensions.*.values.required' => __('Enter at least one value, one per line.'),
            ],
        );

        $this->run(function (): void {
            $admin = $this->admin();
            $election = $this->manageableElection();
            $definition = $this->scopeDefinitionFromForm();
            $service = app(ElectionScopeService::class);

            if ($this->editingGroupId === null) {
                $service->createGroup($admin, $election, $this->name, $definition);
                $this->status = __('Representation group created.');
            } else {
                $service->updateGroup($admin, $election, $this->editingGroupId, $this->name, $definition);
                $this->status = __('Representation group updated.');
            }

            $this->resetForm();
        });
    }

    public function deleteGroup(int $groupId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($groupId): void {
            $admin = $this->admin();
            $election = $this->manageableElection();

            app(ElectionScopeService::class)->deleteGroup($admin, $election, $groupId);

            if ($this->editingGroupId === $groupId) {
                $this->resetForm();
            }

            $this->status = __('Representation group deleted.');
        });
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    /**
     * Run a state-changing action and turn every expected failure into a message instead of an error page:
     * invalid input / group in use / duplicate name, the election no longer being editable, a group that is gone.
     */
    private function run(Closure $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            $this->problem = $exception->getMessage();
        } catch (AuthorizationException) {
            $this->problem = ElectionScopeService::MSG_NOT_EDITABLE;
            $this->resetForm();
        } catch (ModelNotFoundException) {
            $this->problem = __('That representation group no longer exists. The list has been refreshed.');
            $this->resetForm();
        } catch (QueryException $exception) {
            // MySQL/MariaDB 1205 = lock wait timeout, 1213 = deadlock: another administrator is changing this election.
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw $exception;
            }

            $this->problem = __('This election is being changed by someone else right now. Please try again in a moment.');
        }

        unset($this->groups, $this->canManage);
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function loadElection(): Election
    {
        return Election::query()
            ->select(['id', 'name', 'state', 'current_config_version_id', 'locked_config_version_id'])
            ->findOrFail($this->electionId);
    }

    /** A fresh read + authorization for the CURRENT state (never a cached computed property). */
    private function manageableElection(): Election
    {
        $election = $this->loadElection();
        Gate::forUser($this->admin())->authorize('manageScope', $election);

        return $election;
    }

    /** Make the submitted rows predictable before validating: lower-case trimmed keys, text-only cells, bounded size. */
    private function normalizeForm(): void
    {
        $rows = [];

        foreach (array_values($this->dimensions) as $row) {
            $row = is_array($row) ? $row : [];
            $rows[] = [
                'key' => is_string($row['key'] ?? null) ? strtolower(trim($row['key'])) : '',
                'values' => is_string($row['values'] ?? null) ? $row['values'] : '',
            ];
        }

        $this->dimensions = array_slice($rows, 0, ElectionScopeService::MAX_DIMENSIONS + 1);
    }

    /** @return array<string, list<string>> */
    private function scopeDefinitionFromForm(): array
    {
        $scope = [];

        foreach ($this->dimensions as $row) {
            $key = $row['key'];

            if (array_key_exists($key, $scope)) {
                throw new InvalidArgumentException("The scope dimension [{$key}] appears more than once.");
            }

            $lines = preg_split('/\r\n|\r|\n/', $row['values']) ?: [];
            $values = array_values(array_filter(array_map('trim', $lines), fn (string $value) => $value !== ''));

            if ($values === []) {
                throw new InvalidArgumentException("Add at least one value for the [{$key}] dimension.");
            }

            $scope[$key] = $values;
        }

        return $scope;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->name = '';
        $this->dimensions = [['key' => '', 'values' => '']];
        $this->editingGroupId = null;
    }
}; ?>

<div class="theme-2k26 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <section class="min-w-0 space-y-2">
            <p class="ui-eyebrow">{{ __('Election scope') }}</p>
            <h2 class="ui-section-title break-words">{{ $this->election->name }}</h2>
            <p class="text-sm text-muted">{{ __('Representation groups organize an election’s contests around dimensions you configure. They do not decide who may vote or stand; eligibility is a separate, later step.') }}</p>
        </section>
        <x-ui.button icon="arrow-left" :href="route('admin.elections.show', $electionId)" wire:navigate>{{ __('Election workspace') }}</x-ui.button>
    </div>

    @if ($status)
        <x-ui.alert tone="success" wire:key="scope-status">{{ $status }}</x-ui.alert>
    @endif

    @if ($problem)
        <x-ui.alert tone="danger" wire:key="scope-problem">{{ $problem }}</x-ui.alert>
    @endif

    @if (! $this->canManage)
        <x-ui.alert tone="info">{{ __('This election is not an editable draft, so its scope is shown read-only.') }}</x-ui.alert>
    @endif

    <div @class(['grid items-start gap-6', 'xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.9fr)]' => $this->canManage])>
        @if ($this->canManage)
            <x-ui.panel :title="$editingGroupId === null ? __('Create representation group') : __('Edit representation group')" :description="__('Choose a dimension key (lowercase letters, numbers and underscores) and enter one value per line. Keys and values are entirely up to you; none are preselected.')">
                <form wire:submit="save" class="space-y-4" novalidate>
                    <flux:field>
                        <flux:label>{{ __('Group name') }}</flux:label>
                        <flux:input wire:model="name" maxlength="255" placeholder="{{ __('Name this group') }}" />
                        <flux:error name="name" />
                    </flux:field>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold">{{ __('Scope dimensions') }}</h3>
                            <x-ui.button type="button" size="sm" wire:click="addDimension">{{ __('Add dimension') }}</x-ui.button>
                        </div>

                        @foreach ($dimensions as $index => $dimension)
                            <div wire:key="scope-dimension-{{ $index }}" class="grid gap-3 rounded-ui border border-line p-3 sm:grid-cols-2">
                                <flux:field>
                                    <flux:label>{{ __('Dimension key') }}</flux:label>
                                    <flux:input wire:model="dimensions.{{ $index }}.key" placeholder="{{ __('e.g. dimension_name') }}" />
                                    <flux:error name="dimensions.{{ $index }}.key" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>{{ __('Values (one per line)') }}</flux:label>
                                    <flux:textarea wire:model="dimensions.{{ $index }}.values" rows="3" placeholder="{{ __('One value per line') }}" />
                                    <flux:error name="dimensions.{{ $index }}.values" />
                                </flux:field>
                                @if (count($dimensions) > 1)
                                    <div class="flex justify-end sm:col-span-2">
                                        <x-ui.button type="button" size="sm" wire:click="removeDimension({{ $index }})">{{ __('Remove dimension') }}</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        <flux:error name="dimensions" />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                            {{ $editingGroupId === null ? __('Create group') : __('Save changes') }}
                        </x-ui.button>
                        @if ($editingGroupId !== null)
                            <x-ui.button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.panel>
        @endif

        <x-ui.panel :title="__('Representation groups')" :description="$this->canManage ? __('Groups saved for this election. A group used by a contest cannot be deleted.') : __('Groups saved for this election.')">
            @if ($this->groups->isEmpty())
                <x-ui.empty-state icon="square-3-stack-3d" :title="__('No representation groups yet')" :description="$this->canManage ? __('Create a group to define the dimensions used to organize this election’s contests.') : __('No groups were configured while this election was a draft.')" />
            @else
                <div class="divide-y divide-line">
                    @foreach ($this->groups as $group)
                        <article wire:key="representation-group-{{ $group->id }}" class="space-y-3 py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="font-semibold break-words">{{ $group->name }}</h3>
                                    <p class="text-xs text-muted">{{ trans_choice(':count contest assigned|:count contests assigned', (int) $group->contests_count, ['count' => (int) $group->contests_count]) }}</p>
                                </div>
                                @if ($this->canManage)
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button type="button" size="sm" wire:click="editGroup({{ $group->id }})">{{ __('Edit') }}</x-ui.button>
                                        @if ((int) $group->contests_count === 0)
                                            <x-ui.button type="button" size="sm" wire:click="deleteGroup({{ $group->id }})" wire:confirm="{{ __('Delete this representation group?') }}">{{ __('Delete') }}</x-ui.button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <dl class="space-y-2 text-sm">
                                @foreach ((array) $group->scope_definition_json as $dimensionKey => $values)
                                    <div>
                                        <dt class="text-xs font-semibold text-muted">{{ $dimensionKey }}</dt>
                                        <dd class="break-words">{{ implode(', ', array_map('strval', (array) $values)) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    </div>
</div>
