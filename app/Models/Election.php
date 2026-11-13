<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Election event and lifecycle root (`elections`).
 *
 * Nothing is mass-assignable: a draft is created only by
 * App\Services\Election\ElectionDraftService, which sets the state, the creator and the
 * initial configuration version together in one transaction. `type` is a descriptive label
 * (the kind of election), never a code branch (docs/DATABASE.md). The chosen starting template is kept separately in
 * `template_key` (provenance only; NULL = "Customize from scratch"), and `creation_key` makes creation idempotent.
 */
class Election extends Model
{
    public const STATE_DRAFT = 'DRAFT';

    /**
     * The approved lifecycle (CLAUDE.md, docs/ELECTION_CONFIGURATION.md), mirroring the `elections.state` enum.
     *
     * @var list<string>
     */
    public const STATES = [
        'DRAFT', 'READY', 'APPROVED', 'SNAPSHOTTED', 'LOCKED', 'SCHEDULED', 'OPEN', 'PAUSED', 'CLOSED',
        'RECONCILING', 'RESULTS_PENDING_VALIDATION', 'VALIDATED', 'OFFICIALLY_ANNOUNCED', 'FINALIZED',
    ];

    /**
     * States in which scope (representation groups) may be edited. DECISION (docs/OPEN_DECISIONS.md, Phase 04B):
     * DRAFT only. docs/DOMAIN_MODEL_PROPOSAL.md allows normalized configuration to be edited in DRAFT and READY, but no
     * DRAFT -> READY transition (or rule for what an edit does to a READY election) exists yet. Widening this to include
     * 'READY' is a one-line change here; the policy and the service both read this constant.
     *
     * @var list<string>
     */
    public const SCOPE_EDITABLE_STATES = [self::STATE_DRAFT];

    protected $table = 'elections';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function currentConfigVersion(): BelongsTo
    {
        return $this->belongsTo(ElectionConfigVersion::class, 'current_config_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function representationGroups(): HasMany
    {
        return $this->hasMany(RepresentationGroup::class);
    }

    /**
     * Whether scope may be edited right now: an editable state, nothing locked, and the CURRENT configuration version
     * belongs to this election, is still a DRAFT and has no frozen JSON. Pure: callers pass the version row they
     * loaded (the service passes the row it locked), so the policy and the service cannot disagree.
     */
    public function allowsScopeEditing(?ElectionConfigVersion $currentVersion): bool
    {
        return in_array($this->state, self::SCOPE_EDITABLE_STATES, true)
            && $this->locked_config_version_id === null
            && $this->current_config_version_id !== null
            && $currentVersion !== null
            && (int) $currentVersion->getKey() === (int) $this->current_config_version_id
            && (int) $currentVersion->election_id === (int) $this->getKey()
            && $currentVersion->status === ElectionConfigVersion::STATUS_DRAFT
            && $currentVersion->config_json === null;
    }
}
