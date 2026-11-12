<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
