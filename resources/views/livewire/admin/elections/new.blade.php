<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Services\Election\ElectionDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04A: create an election DRAFT from its basic information: name, optional description, election type and an
 * optional starting template ("" = Customize from scratch). The type (what kind of election) and the template (a
 * starting point) are separate choices. Everything is written by ElectionDraftService in one transaction; the creator
 * comes from the admin guard, the state is always DRAFT, and the unique per-form submission key makes a repeated
 * submission create only one election.
 */
new #[Layout('components.layouts.admin')] #[Title('New Election Draft')] class extends Component
{
    public string $name = '';

    public string $description = '';

    public string $type = '';

    /** '' = "Customize from scratch" (stored as NULL). */
    public string $template = '';

    /** Identifies THIS form. Not client-editable; a repeat of the same submission returns the first result. */
    #[Locked]
    public string $submissionKey = '';

    #[Locked]
    public bool $submitted = false;

    public string $problem = '';

    public function mount(): void
    {
        Gate::forUser($this->admin())->authorize('create', Election::class);
        $this->submissionKey = (string) Str::uuid();
    }

    public function save(): void
    {
        $admin = $this->admin();
        $this->problem = '';

        if ($this->submitted) {
            return; // a second click on the same form instance does nothing
        }

        $this->name = trim((string) preg_replace('/\s+/u', ' ', $this->name));
        $this->description = trim($this->description);

        $this->validate([
            'name' => ['required', 'string', 'max:'.ElectionDraftService::MAX_NAME_LENGTH],
            'description' => ['nullable', 'string', 'max:'.ElectionDraftService::MAX_DESCRIPTION_LENGTH],
            'type' => ['required', 'string', Rule::in(ElectionDraftService::typeKeys())],
            'template' => ['nullable', 'string', Rule::in(ElectionDraftService::templateKeys())],
        ], [], ['name' => 'election name', 'type' => 'election type', 'template' => 'starting point']);

        try {
            [$election, $created] = app(ElectionDraftService::class)->createDraft(
                $admin, $this->name, $this->description === '' ? null : $this->description, $this->type,
                $this->template === '' ? null : $this->template, $this->submissionKey,
            );
        } catch (AuthorizationException) {
            abort(403);
        } catch (Throwable $e) {
            report($e);
            $this->problem = __('The election draft could not be created. Nothing was saved.');

            return;
        }

        $this->submitted = true;
        session()->flash('election_status', $created
            ? __('Election draft ":name" was created.', ['name' => $election->name])
            : __('Election draft ":name" was already created from this form.', ['name' => $election->name]));

        $this->redirectRoute('admin.elections.show', ['election' => $election->id], navigate: true);
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }
}; ?>

@php
    $types = (array) config('comelec.election_types');
    $templates = (array) config('comelec.election_templates');
@endphp

<div class="theme-2k26 space-y-6">
    <section class="space-y-2">
        <h2 class="ui-section-title">{{ __('New election draft') }}</h2>
        <p class="max-w-3xl text-sm text-muted">{{ __('Enter the basic information. The draft starts in the DRAFT state with its first configuration version; scope, contests, eligibility, candidates, ballot rules and the schedule are configured in later steps.') }}</p>
    </section>

    @if ($problem !== '')
        <x-ui.alert tone="danger" wire:key="problem">{{ $problem }}</x-ui.alert>
    @endif

    <x-ui.panel :title="__('Basic information')" class="max-w-3xl">
        <form wire:submit="save" class="space-y-6" novalidate>
            <flux:field>
                <flux:label>{{ __('Election name') }}</flux:label>
                <flux:input wire:model="name" maxlength="255" autocomplete="off" required :placeholder="__('For example: 2K26 Student Council Election')" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }} <span class="font-normal text-muted">({{ __('optional') }})</span></flux:label>
                <flux:textarea wire:model="description" rows="3" maxlength="2000" />
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Election type') }}</flux:label>
                <flux:select wire:model="type" required>
                    <flux:select.option value="">{{ __('Select the kind of election…') }}</flux:select.option>
                    @foreach ($types as $key => $label)
                        <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>{{ __('What kind of election this is. It does not decide who may vote or stand; that is the election scope, set in a later step.') }}</flux:description>
                <flux:error name="type" />
            </flux:field>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium">{{ __('Starting point') }} <span class="font-normal text-muted">({{ __('optional') }})</span></legend>
                <x-ui.alert tone="info">{{ __('Template contents are not defined yet, so choosing a template applies no configuration. The choice is recorded on the draft for reference only. The election type above is chosen separately.') }}</x-ui.alert>

                <div class="grid gap-3 sm:grid-cols-2" role="radiogroup">
                    @foreach (['' => __('Customize from scratch')] + $templates as $key => $label)
                        <label wire:key="template-{{ $key === '' ? 'none' : $key }}" @class(['flex min-h-12 cursor-pointer items-center gap-3 rounded-ui border px-4 py-3 text-sm font-medium focus-within:ring-2 focus-within:ring-focus', 'border-accent bg-surface-subtle' => $template === (string) $key, 'border-line hover:border-line-strong' => $template !== (string) $key])>
                            <input type="radio" wire:model.live="template" name="template" value="{{ $key }}" class="size-4 accent-[var(--ui-accent)]">
                            <span>{{ __($label) }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="template" />
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save" :disabled="$submitted">{{ __('Create draft') }}</x-ui.button>
                <x-ui.button :href="route('admin.elections')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                <span wire:loading wire:target="save" class="text-sm text-muted" role="status">{{ __('Creating…') }}</span>
            </div>
        </form>
    </x-ui.panel>
</div>
