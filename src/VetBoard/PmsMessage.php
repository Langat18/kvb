<?php

declare(strict_types=1);

namespace VetBoard;

/**
 * Turns whatever PMS (or the network) replied into a short, plain message for staff.
 * The raw reply never reaches the screen or the database: it is written to the server log under a reference
 * code, which staff can quote to whoever needs to look at the detail.
 */
final class PmsMessage
{
    /** @return string a plain sentence ending with a reference such as "(ref PMS-1A2B3C)" */
    public static function explain(?string $raw, ?int $http = null): string
    {
        $raw  = trim((string) $raw);
        $ref  = 'PMS-' . strtoupper(substr(hash('sha256', $raw . '|' . (string) $http), 0, 6));
        $text = self::classify($raw, (int) $http);

        if ($raw !== '' || $http) {
            error_log('[pms] ref=' . $ref . ' http=' . (int) $http . ' detail=' . mb_substr((string) preg_replace('/\s+/', ' ', $raw), 0, 1500));
        }

        return $text . ' (ref ' . $ref . ')';
    }

    /** For text saved earlier: already-plain messages pass through, anything else is explained now. */
    public static function forDisplay(?string $saved): string
    {
        $saved = trim((string) $saved);
        if ($saved === '' || preg_match('/\(ref PMS-[0-9A-F]{6}\)$/', $saved) === 1) {
            return $saved;
        }

        return self::explain($saved);
    }

    /** True when PMS stopped on an internal error, so part of a batch may already have been stored. */
    public static function failedInsideBatch(?string $raw, ?int $http = null): bool
    {
        return (int) $http >= 500 || (bool) preg_match('/sqlstate|data too long|integrity constraint|syntax error|insert into|truncated|stack trace|exception/i', (string) $raw);
    }

    private static function classify(string $raw, int $http): string
    {
        if (in_array($http, [401, 403], true) || preg_match('/unauthori[sz]ed|forbidden|access token|invalid token|token (is )?(expired|invalid)/i', $raw)) {
            return 'PMS refused our access. KVB needs to issue a current access token. Nothing was credited.';
        }
        if (preg_match('/duplicate entry|already exists?|already (been )?(uploaded|recorded|credited)/i', $raw)) {
            return 'PMS says it already holds these points, so nothing new was added.';
        }
        if (preg_match('/sqlstate|data too long|integrity constraint|syntax error|insert into|truncated|doctrine|stack trace|exception/i', $raw)) {
            return 'PMS could not store these records because of a problem on its side. Nothing was confirmed as credited. Please send the reference to KVB.';
        }
        if (preg_match('/activity.*(not found|unknown|invalid)|(not found|unknown|invalid).*activity/i', $raw)) {
            return 'PMS does not recognise this activity number. Check the number on the event.';
        }
        if (preg_match('/timed? ?out|no answer|could not resolve|connection|unreachable|ssl|curl|unexpected reply|not configured|did not match/i', $raw) || $http >= 500 || $http === 0) {
            return 'PMS did not give a clear answer, so the outcome is not known. Check PMS before sending these again.';
        }
        if (preg_match('/validat|invalid|required|must be/i', $raw)) {
            return 'PMS did not accept the details of these records.';
        }

        return 'PMS could not process these records. Please send the reference to KVB.';
    }
}
