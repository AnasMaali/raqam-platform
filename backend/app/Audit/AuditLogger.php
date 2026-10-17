<?php

namespace App\Audit;

use App\Support\TrackingHash;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail (decisions 22.14, 22.15): who, when, from where (an HMAC, never the address),
 * and the value of every changed field before and after. A secret never reaches the log: the value of a
 * field whose name says it is one is replaced by a marker, so the log shows that it changed, not to what.
 */
final class AuditLogger
{
    public const HIDDEN = '[hidden]';

    private const MAX_VALUE_LENGTH = 2000;

    private const SECRET_FIELD = '/pass(word|phrase)?|secret|token|hash|api_?key|private_?key|credential|otp|signature|salt/i';

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [before, after]
     */
    public static function record(string $action, ?string $subjectTable, ?int $subjectId, array $changes = [], ?int $actorId = null): int
    {
        $actorId ??= auth()->id();
        // Whoever is signed in acts through a browser; the scheduler, queue and commands have no actor and no address worth keeping.
        $inRequest = $actorId !== null;

        $write = function () use ($action, $subjectTable, $subjectId, $changes, $actorId, $inRequest) {
            $id = DB::table('audit_log')->insertGetId([
                'actor_user_id' => $actorId,
                'action' => $action,
                'subject_table' => $subjectId === null ? null : $subjectTable,
                'subject_id' => $subjectId,
                'ip_hash' => $inRequest ? TrackingHash::ip() : null,
                'user_agent' => $inRequest && ($agent = request()->userAgent()) ? mb_substr($agent, 0, 512) : null,
            ]);

            foreach ($changes as $field => [$old, $new]) {
                DB::table('audit_log_changes')->insert([
                    'audit_id' => $id,
                    'field' => mb_substr((string) $field, 0, 63),
                    'old_value' => self::value($field, $old),
                    'new_value' => self::value($field, $new),
                ]);
            }

            return $id;
        };

        return DB::transaction($write);
    }

    public static function isSecret(string $field): bool
    {
        return (bool) preg_match(self::SECRET_FIELD, $field);
    }

    private static function value(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (self::isSecret($field)) {
            return self::HIDDEN;
        }

        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s.uP'),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH).'…' : $text;
    }
}
