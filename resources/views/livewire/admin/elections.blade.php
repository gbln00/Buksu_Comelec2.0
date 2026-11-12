<?php

use App\Models\AdminUser;
use App\Models\Election;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Phase 04A: the election list. It lists EVERY election (any lifecycle state), optionally narrowed by state, and each
 * row opens its read-only workspace. Nothing can be edited, opened for voting, locked or deleted from here.
 */
new #[Layout('components.layouts.admin')] #[Title('Elections')] class extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    #[Url(as: 'state', except: '')]
    public string $state = '';

    public function mount(): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);
        Gate::forUser($admin)->authorize('viewAny', Election::class);

        $this->sanitizeState();
    }

    public function updatedState(): void
    {
        $this->sanitizeState();
        $this->resetPage();
    }

    public function clearFilter(): void
    {
        $this->state = '';
        $this->resetPage();
    }

    #[Computed]
    public function elections(): LengthAwarePaginator
    {
        return DB::table('elections')
            ->leftJoin('election_config_versions', 'election_config_versions.id', '=', 'elections.current_config_version_id')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'elections.created_by')
            ->when($this->state !== '', fn ($q) => $q->where('elections.state', $this->state))
            ->select([
                'elections.id', 'elections.name', 'elections.type', 'elections.state', 'elections.created_at',
                'election_config_versions.version_number', 'election_config_versions.status as version_status',
                'admin_users.display_name as creator_name',
            ])
            ->orderByDesc('elections.created_at')->orderByDesc('elections.id')
            ->paginate(self::PER_PAGE);
    }

    /** Drop a state that is not a lifecycle state, so a hand-edited URL cannot filter on something odd. */
    private function sanitizeState(): void
    {
        if (! in_array($this->state, ['', ...Election::STATES], true)) {
            $this->state = '';
        }
    }
}; ?>

@php
    $page = $this->elections;
    $types = (array) config('comelec.election_types');
    $typeLabel = fn (string $type) => __($types[$type] ?? $type);
    $stateLabel = fn (string $s) => Str::of($s)->lower()->replace('_', ' ')->ucfirst();
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('M j, Y g:i A') : '—';
@endphp

<div class="theme-2k26 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <section class="min-w-0 space-y-2">
            <h2 class="ui-section-title">{{ __('Elections') }}</h2>
            <p class="max-w-3xl text-sm text-muted">{{ __('All elections, newest first. Open one to see its saved details and configuration status. Creating a draft only records its basic information; scope, contests, eligibility, candidates and the schedule are set up in later steps.') }}</p>
        </section>
        <x-ui.button variant="primary" icon="plus" :href="route('admin.elections.new')" wire:navigate>{{ __('New election draft') }}</x-ui.button>
    </div>

    @if (session('election_status'))
        <x-ui.alert tone="success" wire:key="flash">{{ session('election_status') }}</x-ui.alert>
    @endif

    <x-ui.panel :title="__('Elections')" :description="$page->total() === 0 ? ($state === '' ? __('No elections yet.') : __('No elections in this state.')) : __('Showing :from–:to of :total.', ['from' => $page->firstItem(), 'to' => $page->lastItem(), 'total' => $page->total()])">
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <flux:field class="w-full sm:w-64">
                <flux:label>{{ __('State') }}</flux:label>
                <flux:select wire:model.live="state">
                    <flux:select.option value="">{{ __('All states') }}</flux:select.option>
                    @foreach (\App\Models\Election::STATES as $option)
                        <flux:select.option :value="$option">{{ $stateLabel($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
            @if ($state !== '')
                <x-ui.button size="sm" icon="x-mark" wire:click="clearFilter">{{ __('Show all states') }}</x-ui.button>
            @endif
        </div>

        @if ($page->total() === 0)
            @if ($state === '')
                <x-ui.empty-state icon="calendar-days" :title="__('No elections yet')" :description="__('Create the first draft to start configuring an election.')" />
            @else
                <x-ui.empty-state icon="calendar-days" :title="__('No elections in this state')" :description="__('Choose another state, or show all states.')" />
            @endif
        @else
            <div class="relative -mx-4 overflow-x-auto" wire:loading.class="opacity-60" wire:target="state,nextPage,previousPage,clearFilter">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <caption class="sr-only">{{ __('Elections') }}</caption>
                    <thead>
                        <tr class="border-b border-line text-xs text-muted">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Election') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Type') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('State') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Configuration') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Created by') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Created') }}</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($page as $row)
                            <tr wire:key="election-{{ $row->id }}">
                                <td class="px-4 py-3 font-semibold break-words">
                                    <a href="{{ route('admin.elections.show', $row->id) }}" wire:navigate class="rounded-ui hover:underline focus-ui">{{ $row->name }}</a>
                                </td>
                                <td class="px-2 py-3 text-muted">{{ $typeLabel($row->type) }}</td>
                                <td class="px-2 py-3"><x-ui.status-badge :status="$row->state" /></td>
                                <td class="px-2 py-3 text-muted">
                                    @if ($row->version_number !== null)
                                        {{ __('Version :n', ['n' => $row->version_number]) }} · {{ Str::title(strtolower($row->version_status)) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-2 py-3 text-muted">{{ $row->creator_name ?? '—' }}</td>
                                <td class="px-2 py-3 text-muted">{{ $fmtTime($row->created_at) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button size="sm" :href="route('admin.elections.show', $row->id)" wire:navigate :aria-label="__('Open :name', ['name' => $row->name])">{{ __('Open') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($page->hasPages())
                <nav class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-muted" aria-label="{{ __('Election pages') }}">
                    <x-ui.button size="sm" wire:click="previousPage" :disabled="$page->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                    <span class="tabular-nums">{{ __('Page :p of :n', ['p' => $page->currentPage(), 'n' => $page->lastPage()]) }}</span>
                    <x-ui.button size="sm" wire:click="nextPage" :disabled="! $page->hasMorePages()">{{ __('Next') }}</x-ui.button>
                </nav>
            @endif
        @endif
    </x-ui.panel>
</div>
