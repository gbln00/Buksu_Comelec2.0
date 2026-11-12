<?php

namespace App\Services\Election;

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 04A: create an election DRAFT together with its initial configuration version.
 *
 *  - one transaction: the election, its version 1 (`DRAFT`, `config_json` NULL) and the
 *    `current_config_version_id` link are created together or not at all, and audited in the
 *    same transaction;
 *  - the creator is always the authenticated administrator passed in, never request input; the state is always DRAFT;
 *  - `type` is the kind of election; `template_key` is the optional starting template, recorded for provenance only
 *    (no template contents are defined, so nothing is initialized from it);
 *  - idempotency is durable and enforced by the database: the per-form submission key is stored in the unique
 *    `elections.creation_key` in the SAME transaction as the election. A repeat of a submission (double click, retry,
 *    replay, two concurrent requests, a lost cache) can therefore never create a second election: the loser of a race
 *    fails on the unique index, its transaction rolls back completely, and it returns the election that won.
 */
class ElectionDraftService
{
    public const MAX_NAME_LENGTH = 255;

    public const MAX_DESCRIPTION_LENGTH = 2000;

    /** The allowed `type` values (configured). @return list<string> */
    public static function typeKeys(): array
    {
        return array_keys((array) config('comelec.election_types'));
    }

    /** The allowed `template_key` values (configured). "Customize from scratch" is no template (null). @return list<string> */
    public static function templateKeys(): array
    {
        return array_keys((array) config('comelec.election_templates'));
    }

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{0: Election, 1: bool} the election and whether this call created it (false = replay of an earlier submission)
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function createDraft(
        AdminUser $admin,
        string $name,
        ?string $description,
        string $type,
        ?string $template,
        string $submissionKey,
    ): array {
        Gate::forUser($admin)->authorize('create', Election::class);

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Invalid election name.');
        }

        $description = $description === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $description));
        $description = $description === '' ? null : $description;
        if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException('Invalid election description.');
        }

        if (! in_array($type, self::typeKeys(), true)) {
            throw new InvalidArgumentException('Unknown election type.');
        }
        if ($template !== null && ! in_array($template, self::templateKeys(), true)) {
            throw new InvalidArgumentException('Unknown election template.');
        }
        if (! Str::isUuid($submissionKey)) {
            throw new InvalidArgumentException('Invalid submission key.');
        }
        $submissionKey = strtolower($submissionKey);

        if (($existing = $this->findByKey($submissionKey)) !== null) {
            return [$this->replayFor($existing, $admin), false];
        }

        try {
            return [$this->create($admin, $name, $description, $type, $template, $submissionKey), true];
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with another request carrying the same key (the whole transaction was rolled back).
            $winner = $this->findByKey($submissionKey);
            if ($winner === null) {
                throw $e; // a different unique violation: not ours to hide
            }

            return [$this->replayFor($winner, $admin), false];
        }
    }

    /** Overridable seam so a test can reproduce the "both requests passed the check" interleaving. */
    protected function findByKey(string $submissionKey): ?Election
    {
        return Election::query()->where('creation_key', $submissionKey)->first();
    }

    /** A key is only ever replayed to the administrator who created the election with it. */
    private function replayFor(Election $election, AdminUser $admin): Election
    {
        if ((int) $election->created_by !== (int) $admin->getKey()) {
            throw new InvalidArgumentException('This submission key belongs to another administrator.');
        }

        return $election;
    }

    private function create(AdminUser $admin, string $name, ?string $description, string $type, ?string $template, string $submissionKey): Election
    {
        return DB::transaction(function () use ($admin, $name, $description, $type, $template, $submissionKey): Election {
            $election = new Election;
            $election->forceFill([
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'template_key' => $template,
                'creation_key' => $submissionKey,
                'state' => Election::STATE_DRAFT,
                'created_by' => $admin->getKey(),
            ])->save();

            $version = new ElectionConfigVersion;
            $version->forceFill([
                'election_id' => $election->getKey(),
                'version_number' => 1,
                'status' => ElectionConfigVersion::STATUS_DRAFT,
                'config_json' => null,
                'proposed_by' => $admin->getKey(),
            ])->save();

            $election->forceFill(['current_config_version_id' => $version->getKey()])->save();

            $this->audit->record(
                eventType: 'election.draft_created',
                actorType: 'ADMIN',
                actorId: $admin->getKey(),
                targetType: 'election',
                targetId: $election->getKey(),
                description: 'Election draft created with its initial configuration version.',
                metadata: ['election_type' => $type, 'template' => $template, 'config_version_number' => 1],
                electionId: (int) $election->getKey(),
            );

            return $election;
        });
    }
}
