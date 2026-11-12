<?php

use App\Models\AdminUser;
use App\Models\Election;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04A: the read-only election workspace. It shows what was saved and the configuration status, and nothing else:
 * no edit, lock, approve, schedule or delete action exists here (later phases). Only the election's id is kept in
 * component state; the row is read with explicit columns on each render.
 */
new #[Layout('components.layouts.admin')] #[Title('Election')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    public function mount(Election $election): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);
        Gate::forUser($admin)->authorize('view', $election);

        $this->electionId = (int) $election->getKey();
    }

    #[Computed]
    public function election(): object
    {
        return DB::table('elections')
            ->leftJoin('election_config_versions as current_version', 'current_version.id', '=', 'elections.current_config_version_id')
            ->leftJoin('election_config_versions as locked_version', 'locked_version.id', '=', 'elections.locked_config_version_id')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'elections.created_by')
            ->where('elections.id', $this->electionId)
            ->select([
                'elections.id', 'elections.name', 'elections.description', 'elections.type', 'elections.template_key',
                'elections.state', 'elections.timezone', 'elections.opens_at', 'elections.closes_at',
                'elections.created_at', 'elections.updated_at',
                'current_version.version_number as version_number', 'current_version.status as version_status',
                'current_version.config_json as version_config_json',
                'locked_version.version_number as locked_version_number',
                'admin_users.display_name as creator_name',
            ])
            ->firstOrFail();
    }
}; ?>

@php
    $e = $this->election;
    $types = (array) config('comelec.election_types');
    $templates = (array) config('comelec.election_templates');
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('M j, Y g:i A') : '—';
    $versionStatus = $e->version_status === null ? null : \Illuminate\Support\Str::title(strtolower($e->version_status));
@endphp

<div class="theme-2k26 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <section class="min-w-0 space-y-2">
            <p class="ui-eyebrow">{{ __('Election') }}</p>
            <h2 class="ui-section-title break-words">{{ $e->name }}</h2>
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                <x-ui.status-badge :status="$e->state" />
                <span>{{ __($types[$e->type] ?? $e->type) }}</span>
            </p>
        </section>
        <x-ui.button icon="arrow-left" :href="route('admin.elections')" wire:navigate>{{ __('All elections') }}</x-ui.button>
    </div>

    @if (session('election_status'))
        <x-ui.alert tone="success" wire:key="flash">{{ session('election_status') }}</x-ui.alert>
    @endif

    <x-ui.alert tone="info">{{ __('This is a read-only view of the saved draft. Configuring the election (scope, contests, eligibility, candidates, ballot rules, schedule) is not available yet.') }}</x-ui.alert>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-ui.panel :title="__('Saved details')" class="min-w-0">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-muted">{{ __('Name') }}</dt><dd class="break-words">{{ $e->name }}</dd></div>
                <div><dt class="text-muted">{{ __('Description') }}</dt><dd class="break-words whitespace-pre-line">{{ $e->description ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('Election type') }}</dt><dd>{{ __($types[$e->type] ?? $e->type) }}</dd></div>
                <div>
                    <dt class="text-muted">{{ __('Starting point') }}</dt>
                    <dd>
                        @if ($e->template_key === null)
                            {{ __('Customize from scratch') }}
                        @else
                            {{ __($templates[$e->template_key] ?? $e->template_key) }}
                        @endif
                    </dd>
                    <dd class="mt-1 text-xs text-muted">
                        @if ($e->template_key === null)
                            {{ __('No template was selected.') }}
                        @else
                            {{ __('Recorded for reference only. Template contents are not defined yet, so no template configuration has been applied to this draft.') }}
                        @endif
                    </dd>
                </div>
                <div><dt class="text-muted">{{ __('Scope') }}</dt><dd>{{ __('Not configured yet') }} <span class="text-xs text-muted">({{ __('set in a later configuration step') }})</span></dd></div>
                <div><dt class="text-muted">{{ __('Schedule') }}</dt><dd>@if ($e->opens_at || $e->closes_at) {{ $fmtTime($e->opens_at) }} → {{ $fmtTime($e->closes_at) }} @else {{ __('Not scheduled yet') }} @endif <span class="text-xs text-muted">({{ $e->timezone }})</span></dd></div>
            </dl>
        </x-ui.panel>

        <x-ui.panel :title="__('Configuration status')" class="min-w-0">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-muted">{{ __('Election state') }}</dt><dd><x-ui.status-badge :status="$e->state" /></dd></div>
                <div>
                    <dt class="text-muted">{{ __('Current configuration version') }}</dt>
                    <dd>
                        @if ($e->version_number === null)
                            —
                        @else
                            {{ __('Version :n', ['n' => $e->version_number]) }} · <x-ui.status-badge :status="$e->version_status" />
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('Configuration content') }}</dt>
                    <dd>{{ __('None yet') }} <span class="text-xs text-muted">({{ $e->version_config_json === null ? __('not snapshotted') : __('snapshotted') }})</span></dd>
                </div>
                <div><dt class="text-muted">{{ __('Locked configuration version') }}</dt><dd>{{ $e->locked_version_number === null ? __('None') : __('Version :n', ['n' => $e->locked_version_number]) }}</dd></div>
                <div><dt class="text-muted">{{ __('Created by') }}</dt><dd>{{ $e->creator_name ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('Created') }}</dt><dd>{{ $fmtTime($e->created_at) }}</dd></div>
                <div><dt class="text-muted">{{ __('Last updated') }}</dt><dd>{{ $fmtTime($e->updated_at) }}</dd></div>
            </dl>
        </x-ui.panel>
    </div>
</div>
