{{--
    x-admin.sidebar — grouped administrator navigation.
    Entries with a real route are links; everything else is shown but visibly unavailable (not focusable, no href).
    A page is added by giving its entry a 'route' once that route exists: no other change is needed.
    `closable` adds the close button used by the mobile drawer.
--}}
@props(['closable' => false])
@php
    $groups = [
        'Overview' => [
            ['Dashboard', 'admin.home', 'squares-2x2'],
        ],
        'Elections' => [
            ['Student Import', 'admin.data-center', 'circle-stack'],
            ['Student Masterlist', 'admin.students', 'user-group'],
            ['Elections', 'admin.elections', 'calendar-days'],
            ['Eligibility', null, 'identification'],
            ['Candidates', null, 'users'],
            ['Results & Analytics', null, 'chart-bar'],
        ],
        'Governance' => [
            ['Approvals', 'admin.approvals', 'clipboard-document-check'],
            ['Access Issue Reports', 'admin.access-issues', 'lifebuoy'],
            ['Audit & Incidents', null, 'shield-exclamation'],
            ['Admin Logs', null, 'document-text'],
        ],
        'Operations' => [
            ['Backup & Recovery', null, 'archive-box'],
            ['Operational Health', null, 'heart'],
        ],
    ];
@endphp

<div class="flex h-full flex-col">
    <div class="flex items-center justify-between gap-3 px-5 py-5">
        <a href="{{ route('admin.home') }}" class="min-w-0 rounded-ui focus-ui">
            <span class="block text-lg leading-tight font-extrabold tracking-[0.08em] text-ink uppercase">{{ __('BUKSU COMELEC') }}</span>
            <span class="block text-[0.68rem] leading-snug font-semibold tracking-wide text-brass-text uppercase">{{ __('Administration') }}</span>
        </a>
        @if ($closable)
            <x-ui.icon-button icon="x-mark" :label="__('Close navigation')" x-on:click="nav = false" />
        @endif
    </div>

    <nav aria-label="{{ __('Administration') }}" class="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
        @foreach ($groups as $group => $items)
            <div>
                <p class="ui-eyebrow px-3 pb-2 select-none">{{ __($group) }}</p>
                <ul class="space-y-0.5">
                    @foreach ($items as [$label, $route, $icon])
                        @php $current = $route && request()->routeIs($route, $route.'.*'); @endphp
                        <li>
                            @if ($route)
                                <a href="{{ route($route) }}" @if ($current) aria-current="page" @endif
                                   class="group flex min-h-11 items-center gap-3 rounded-ui px-3 text-sm font-medium transition-colors focus-ui {{ $current ? 'bg-accent text-accent-foreground shadow-ui' : 'text-muted hover:bg-surface-subtle hover:text-ink' }}">
                                    <flux:icon :name="$icon" class="size-5 shrink-0 {{ $current ? '' : 'text-brass-text' }}" />
                                    <span class="min-w-0 flex-1 truncate">{{ __($label) }}</span>
                                </a>
                            @else
                                <span aria-disabled="true" title="{{ __('Not available yet') }}"
                                      class="flex min-h-11 cursor-not-allowed items-center gap-3 rounded-ui px-3 text-sm font-medium text-subtle/70 select-none">
                                    <flux:icon :name="$icon" class="size-5 shrink-0 opacity-60" />
                                    <span class="min-w-0 flex-1 py-2 leading-snug">{{ __($label) }}</span>
                                    <span class="shrink-0 rounded-full border border-line px-2 py-0.5 text-[0.65rem] font-semibold tracking-wide text-subtle uppercase">{{ __('Soon') }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</div>
