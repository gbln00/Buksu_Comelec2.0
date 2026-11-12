<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Election;
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

    private function isAuthorizedAdmin(Authenticatable $actor): bool
    {
        return $actor instanceof AdminUser
            && $actor->role === AdminUser::ROLE
            && $this->roster->isIntact();
    }
}
