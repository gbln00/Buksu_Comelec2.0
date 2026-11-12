<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Append-only writer for `audit_logs` (docs/AUDIT_LOGGING.md).
 *
 * Safety net, not a substitute for review: metadata keys that look like they
 * would carry credentials, raw Google identifiers, emails or ballot selections
 * are rejected outright (NON_NEGOTIABLES / SECURITY_AND_PRIVACY).
 * IP address and user-agent are deliberately NOT recorded: AUDIT_LOGGING allows
 * them only "if institutionally approved", and that is unresolved.
 */
class AuditLogger
{
    public const INFO = 'INFO';

    public const WARNING = 'WARNING';

    public const SECURITY = 'SECURITY';

    /** Matches keys that must never appear in audit metadata. */
    private const FORBIDDEN_KEY = '/token|secret|password|credential|google_subject|^sub$|^email$|_email$|selection|candidate_id/i';

    /**
     * $electionId is optional and goes LAST so every existing call keeps working; it fills the nullable
     * audit_logs.election_id column for election-related events (ids only, never election names).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        string $severity = self::INFO,
        ?string $actorType = null,
        int|string|null $actorId = null,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $description = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?int $electionId = null,
    ): void {
        self::assertMetadataIsSafe($metadata);

        DB::table('audit_logs')->insert([
            'event_type' => $eventType,
            'severity' => $severity,
            'actor_type' => $actorType,
            'actor_id' => $actorId === null ? null : (string) $actorId,
            'election_id' => $electionId,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'description' => $description,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);
    }

    /**
     * Keyed, truncated fingerprint so repeated attempts can be correlated
     * without ever storing the raw Google subject.
     */
    public function fingerprint(string $value): string
    {
        $key = hash_hmac('sha256', 'comelec-audit-fingerprint-v1', (string) config('app.key'));

        return substr(hash_hmac('sha256', $value, $key), 0, 16);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    public static function assertMetadataIsSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                throw new InvalidArgumentException("Audit/request metadata key [{$key}] is not allowed.");
            }

            if (is_array($value)) {
                self::assertMetadataIsSafe($value);
            }
        }
    }
}
