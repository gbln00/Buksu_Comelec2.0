<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Services\Auth\AdminRoster;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Election administration is limited to an authorized administrator on an intact roster.
 * Parameters are typed as the generic Authenticatable on purpose: a student reaching this
 * policy must get `false`, not a TypeError (same convention as ChangeRequestPolicy).
 */
class ElectionPolicy
{
    public function __construct(private readonly AdminRoster $roster) {}

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    public function view(Authenticatable $actor, Election $election): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    /** Reading an election's scope: any authorized administrator, in any lifecycle state. */
    public function viewScope(Authenticatable $actor, Election $election): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    /**
     * Changing an election's scope: an authorized administrator AND an election that still allows it
     * (Election::allowsScopeEditing). The service re-checks this on the rows it has locked.
     */
    public function manageScope(Authenticatable $actor, Election $election): bool
    {
        if (! $this->isAuthorizedAdmin($actor) || $election->current_config_version_id === null) {
            return false;
        }

        $version = ElectionConfigVersion::query()
            ->select(['id', 'election_id', 'status', 'config_json'])
            ->whereKey($election->current_config_version_id)
            ->first();

        return $election->allowsScopeEditing($version);
    }

    private function isAuthorizedAdmin(Authenticatable $actor): bool
    {
        return $actor instanceof AdminUser
            && $actor->role === AdminUser::ROLE
            && $this->roster->isIntact();
    }
}
