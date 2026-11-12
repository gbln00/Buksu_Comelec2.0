<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of an election's configuration (`election_config_versions`).
 *
 * `config_json` is the frozen artifact written at snapshot time; it stays NULL while the version
 * is a DRAFT (docs/DATABASE.md "Source of truth rule"). Nothing is mass-assignable.
 */
class ElectionConfigVersion extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    protected $table = 'election_config_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['config_json' => 'array'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }
}
