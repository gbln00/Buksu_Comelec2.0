<?php

namespace App\Services\Election;

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\RepresentationGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Phase 04B: transactional management of an election's representation groups ("scope").
 *
 * What a group is: a named, election-owned set of configured dimensions, stored as
 * `scope_definition_json` = { dimension_key: [text values] }. The keys and values are entirely administrator
 * configuration - this class contains no institutional list of any kind and no eligibility rule. A group
 * does not decide who may vote or stand; that is a later workflow.
 *
 * Every write:
 *  - runs in ONE transaction that first locks the election row and its CURRENT configuration version row
 *    (lock order: election -> config version -> group), so a concurrent state change cannot slip between the check and
 *    the write, and scope writes for one election serialize on that lock;
 *  - re-checks, on the locked rows, that the administrator is authorized and the election still allows scope edits
 *    (Election::allowsScopeEditing: editable state, nothing locked, current version DRAFT with no frozen JSON);
 *  - only ever touches groups whose election_id is the locked election (a forged group id from another election
 *    is a ModelNotFoundException);
 *  - writes its audit event in the same transaction, with the dedicated `audit_logs.election_id` column set and no
 *    scope values in the metadata;
 *  - never creates a configuration version (scope rows hang off the election, see docs/OPEN_DECISIONS.md).
 *
 * Exceptions: InvalidArgumentException = invalid input / group in use / duplicate name (message is safe to show);
 * AuthorizationException = not an authorized administrator, or the election no longer allows edits;
 * ModelNotFoundException = the election or the group does not exist (for this election).
 */
class ElectionScopeService
{
    public const MAX_GROUP_NAME_LENGTH = 255;

    public const MAX_DIMENSIONS = 20;

    public const MAX_VALUES_PER_DIMENSION = 100;

    public const MAX_DIMENSION_KEY_LENGTH = 80;

    public const MAX_DIMENSION_VALUE_LENGTH = 255;

    public const MSG_NOT_EDITABLE = 'The scope of this election can no longer be edited.';

    public const MSG_IN_USE = 'This representation group is assigned to one or more contests and cannot be deleted.';

    public const MSG_DUPLICATE_NAME = 'This election already has a representation group with that name.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, list<string>>  $scopeDefinition
     */
    public function createGroup(AdminUser $admin, Election $election, string $name, array $scopeDefinition): RepresentationGroup
    {
        $name = $this->normalizeName($name);
        $scopeDefinition = $this->normalizeScopeDefinition($scopeDefinition);

        return DB::transaction(function () use ($admin, $election, $name, $scopeDefinition): RepresentationGroup {
            $locked = $this->lockEditableElection($admin, (int) $election->getKey());
            $this->assertNameAvailable((int) $locked->getKey(), $name, null);

            $group = new RepresentationGroup;
            $group->forceFill([
                'election_id' => $locked->getKey(),
                'name' => $name,
                'scope_definition_json' => $scopeDefinition,
            ])->save();

            $this->audit->record(
                eventType: 'election.scope_group_created',
                actorType: 'ADMIN',
                actorId: $admin->getKey(),
                targetType: 'representation_group',
                targetId: $group->getKey(),
                description: 'Election representation group created.',
                metadata: $this->auditMetadata($group, $scopeDefinition),
                electionId: (int) $locked->getKey(),
            );

            return $group;
        });
    }

    /**
     * @param  array<string, list<string>>  $scopeDefinition
     */
    public function updateGroup(AdminUser $admin, Election $election, int $groupId, string $name, array $scopeDefinition): RepresentationGroup
    {
        $name = $this->normalizeName($name);
        $scopeDefinition = $this->normalizeScopeDefinition($scopeDefinition);

        return DB::transaction(function () use ($admin, $election, $groupId, $name, $scopeDefinition): RepresentationGroup {
            $locked = $this->lockEditableElection($admin, (int) $election->getKey());
            $group = $this->lockGroup($locked, $groupId);
            $this->assertNameAvailable((int) $locked->getKey(), $name, (int) $group->getKey());

            $group->forceFill([
                'name' => $name,
                'scope_definition_json' => $scopeDefinition,
            ])->save();

            $this->audit->record(
                eventType: 'election.scope_group_updated',
                actorType: 'ADMIN',
                actorId: $admin->getKey(),
                targetType: 'representation_group',
                targetId: $group->getKey(),
                description: 'Election representation group updated.',
                metadata: $this->auditMetadata($group, $scopeDefinition),
                electionId: (int) $locked->getKey(),
            );

            return $group;
        });
    }

    public function deleteGroup(AdminUser $admin, Election $election, int $groupId): void
    {
        DB::transaction(function () use ($admin, $election, $groupId): void {
            $locked = $this->lockEditableElection($admin, (int) $election->getKey());
            $group = $this->lockGroup($locked, $groupId);

            if ($this->isGroupAssigned((int) $locked->getKey(), (int) $group->getKey())) {
                throw new InvalidArgumentException(self::MSG_IN_USE);
            }

            $id = (int) $group->getKey();

            try {
                $group->delete();
            } catch (QueryException $exception) {
                // Backstop: contests_rg_same_election_fk refuses to orphan a contest even if the check above raced.
                if ((string) $exception->getCode() === '23000') {
                    throw new InvalidArgumentException(self::MSG_IN_USE, 0, $exception);
                }

                throw $exception;
            }

            $this->audit->record(
                eventType: 'election.scope_group_deleted',
                actorType: 'ADMIN',
                actorId: $admin->getKey(),
                targetType: 'representation_group',
                targetId: $id,
                description: 'Election representation group deleted.',
                metadata: ['representation_group_id' => $id],
                electionId: (int) $locked->getKey(),
            );
        });
    }

    /** Overridable seam (tests reproduce the "check passed, then a contest appeared" race through it). */
    protected function isGroupAssigned(int $electionId, int $groupId): bool
    {
        return DB::table('contests')
            ->where('election_id', $electionId)
            ->where('representation_group_id', $groupId)
            ->exists();
    }

    /**
     * Lock the election row, then its current configuration version row; refuse unless the administrator is
     * authorized and the (locked, fresh) rows still allow scope edits.
     */
    private function lockEditableElection(AdminUser $admin, int $electionId): Election
    {
        $election = Election::query()->whereKey($electionId)->lockForUpdate()->firstOrFail();

        $version = $election->current_config_version_id === null
            ? null
            : ElectionConfigVersion::query()
                ->select(['id', 'election_id', 'status', 'config_json'])
                ->whereKey($election->current_config_version_id)
                ->where('election_id', $election->getKey())
                ->lockForUpdate()
                ->first();

        Gate::forUser($admin)->authorize('viewScope', $election); // authorized administrator on an intact roster

        if (! $election->allowsScopeEditing($version)) {
            throw new AuthorizationException(self::MSG_NOT_EDITABLE);
        }

        return $election;
    }

    private function lockGroup(Election $election, int $groupId): RepresentationGroup
    {
        return RepresentationGroup::query()
            ->where('election_id', $election->getKey())
            ->whereKey($groupId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Names are unique within an election, case-insensitively. Enforced here, under the election row lock (all scope
     * writes for an election serialize on it, so check-then-write is race-free). There is no database unique index:
     * adding one would need a migration (see docs/OPEN_DECISIONS.md).
     */
    private function assertNameAvailable(int $electionId, string $name, ?int $exceptGroupId): void
    {
        $taken = RepresentationGroup::query()
            ->where('election_id', $electionId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptGroupId !== null, fn ($query) => $query->whereKeyNot($exceptGroupId))
            ->exists();

        if ($taken) {
            throw new InvalidArgumentException(self::MSG_DUPLICATE_NAME);
        }
    }

    /**
     * Counts only - never the dimension keys or values themselves.
     *
     * @param  array<string, list<string>>  $scopeDefinition
     * @return array<string, int>
     */
    private function auditMetadata(RepresentationGroup $group, array $scopeDefinition): array
    {
        return [
            'representation_group_id' => (int) $group->getKey(),
            'dimension_count' => count($scopeDefinition),
            'value_count' => array_sum(array_map('count', $scopeDefinition)),
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = $this->cleanText($name);
        $name = $name === null ? null : trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));

        if ($name === null || $name === '' || mb_strlen($name) > self::MAX_GROUP_NAME_LENGTH) {
            throw new InvalidArgumentException('Enter a group name of 1 to '.self::MAX_GROUP_NAME_LENGTH.' characters, without control characters.');
        }

        return $name;
    }

    /**
     * @param  array<array-key, mixed>  $scopeDefinition
     * @return array<string, list<string>>
     */
    private function normalizeScopeDefinition(array $scopeDefinition): array
    {
        if ($scopeDefinition === [] || count($scopeDefinition) > self::MAX_DIMENSIONS) {
            throw new InvalidArgumentException('Provide between 1 and '.self::MAX_DIMENSIONS.' scope dimensions.');
        }

        $normalized = [];

        foreach ($scopeDefinition as $dimension => $values) {
            if (! is_string($dimension)) {
                throw new InvalidArgumentException('Every scope dimension must have a text key.');
            }

            $dimension = strtolower((string) $this->cleanText($dimension));

            if ($dimension === ''
                || mb_strlen($dimension) > self::MAX_DIMENSION_KEY_LENGTH
                || preg_match('/\A[a-z][a-z0-9_]*\z/', $dimension) !== 1) {
                throw new InvalidArgumentException('Dimension keys must use lowercase letters, numbers and underscores, start with a letter, and be at most '.self::MAX_DIMENSION_KEY_LENGTH.' characters.');
            }

            if (array_key_exists($dimension, $normalized)) {
                throw new InvalidArgumentException("The scope dimension [{$dimension}] appears more than once.");
            }

            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                throw new InvalidArgumentException("Add at least one value for the [{$dimension}] dimension.");
            }

            $clean = [];

            foreach ($values as $value) {
                // Text only: integers, floats, arrays and other types are rejected, never converted.
                if (! is_string($value)) {
                    throw new InvalidArgumentException("Values for [{$dimension}] must be text.");
                }

                $value = $this->cleanText($value);

                if ($value === null || $value === '' || mb_strlen($value) > self::MAX_DIMENSION_VALUE_LENGTH) {
                    throw new InvalidArgumentException('Each scope value must contain 1 to '.self::MAX_DIMENSION_VALUE_LENGTH.' characters, without control characters.');
                }

                // Exact-match de-duplication only. Case and spelling rules belong to the eligibility phase.
                if (! in_array($value, $clean, true)) {
                    $clean[] = $value;
                }
            }

            if (count($clean) > self::MAX_VALUES_PER_DIMENSION) {
                throw new InvalidArgumentException('Provide at most '.self::MAX_VALUES_PER_DIMENSION." values for [{$dimension}].");
            }

            $normalized[$dimension] = $clean;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** Trim (including non-breaking and other Unicode spaces); null when invalid UTF-8 or it holds control/format characters. */
    private function cleanText(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        return (string) preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
    }
}
