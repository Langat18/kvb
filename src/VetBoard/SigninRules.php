<?php

declare(strict_types=1);

namespace VetBoard;

use InvalidArgumentException;
use PDO;
use Throwable;

final class SigninRules
{
    public const RULES = [
        'signin_lead_virtual' => [
            'label'    => 'Virtual sessions: sign-in opens before session end',
            'hint'     => 'Attendees can sign in during this many final minutes of a session.',
            'min'      => 1,
            'max'      => 180,
            'fallback' => 'VIRTUAL_SIGNIN_LEAD_MINUTES',
            'default'  => 45,
        ],
        'signin_lead_onsite' => [
            'label'    => 'On-site sessions: sign-in opens before session end',
            'hint'     => 'Attendees can sign in during this many final minutes of a session.',
            'min'      => 1,
            'max'      => 180,
            'fallback' => 'ONSITE_SIGNIN_LEAD_MINUTES',
            'default'  => 45,
        ],
        'signin_grace' => [
            'label'    => 'Sign-in stays open after a session ends',
            'hint'     => '0 disables late sign-in. Never extends past the start of the next session.',
            'min'      => 0,
            'max'      => 180,
            'fallback' => 'LATE_SIGNIN_GRACE_MINUTES',
            'default'  => 60,
        ],
    ];

    private static ?array $cache = null;

    public static function get(string $key): int
    {
        $rule = self::RULES[$key] ?? throw new InvalidArgumentException("Unknown sign-in rule: {$key}");

        return self::load()[$key]
            ?? (defined($rule['fallback']) ? (int) constant($rule['fallback']) : $rule['default']);
    }

    public static function describe(string $mode): string
    {
        $lead  = self::get($mode === 'onsite' ? 'signin_lead_onsite' : 'signin_lead_virtual');
        $grace = self::get('signin_grace');

        $text = "Points can be earned from the final {$lead} minutes of a session";

        return $text . ($grace > 0
            ? " until {$grace} minutes after it ends, or until the next session begins."
            : ' until it ends.');
    }

    public static function save(array $input, ?int $staffId): void
    {
        $values = [];
        $errors = [];

        foreach (self::RULES as $key => $rule) {
            $n = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);

            if ($n === false || $n < $rule['min'] || $n > $rule['max']) {
                $errors[] = "{$rule['label']} must be a whole number between {$rule['min']} and {$rule['max']}.";
                continue;
            }

            $values[$key] = $n;
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $before = self::load();
        $pdo    = Database::getInstance()->getPdo();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:key, :value, :staff, now())
                 ON CONFLICT (setting_key) DO UPDATE
                 SET setting_value = EXCLUDED.setting_value,
                     updated_by    = EXCLUDED.updated_by,
                     updated_at    = now()'
            );

            foreach ($values as $key => $n) {
                $stmt->execute([':key' => $key, ':value' => (string) $n, ':staff' => $staffId ?: null]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::$cache = null;

        error_log(sprintf(
            'Sign-in rules changed by staff %d: %s -> %s',
            (int) $staffId,
            json_encode($before),
            json_encode($values)
        ));
    }

    public static function lastUpdated(): ?string
    {
        try {
            $value = Database::getInstance()->getPdo()
                ->query('SELECT max(updated_at) FROM app_settings')
                ->fetchColumn();

            return $value ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    // Unreadable or out-of-range values are ignored so the legacy constants apply.
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            $rows = Database::getInstance()->getPdo()
                ->query('SELECT setting_key, setting_value FROM app_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            error_log('SigninRules: settings unavailable: ' . $e->getMessage());

            return self::$cache;
        }

        foreach ($rows as $key => $value) {
            $rule = self::RULES[$key] ?? null;
            $n    = filter_var($value, FILTER_VALIDATE_INT);

            if ($rule !== null && $n !== false && $n >= $rule['min'] && $n <= $rule['max']) {
                self::$cache[$key] = $n;
            }
        }

        return self::$cache;
    }
}
