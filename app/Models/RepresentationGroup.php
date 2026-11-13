<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An election-owned grouping of contests around configurable dimensions (`representation_groups`).
 *
 * `scope_definition_json` is configuration data only: an object of dimension key => list of text values, e.g.
 * {"some_dimension": ["value a", "value b"]}. Dimension keys and values are whatever the administrator configures; the
 * application ships none. It does NOT calculate voter or candidate eligibility - that is a later workflow
 * (docs/ELIGIBILITY_ENGINE.md). Nothing is mass-assignable: rows are written only by ElectionScopeService.
 */
class RepresentationGroup extends Model
{
    protected $table = 'representation_groups';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scope_definition_json' => 'array',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }
}
