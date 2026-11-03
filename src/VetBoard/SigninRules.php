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
            'label'    => 'Opens before end, virtual (min)',
            'min'      => 1,
            'max'      => 180,
            'fallback' => 'VIRTUAL_SIGNIN_LEAD_MINUTES',
            'default'  => 45,
        ],
        'signin_lead_onsite' => [
            'label'    => 'Opens before end, on-site (min)',
            'min'      => 1,
            'max'      => 180,
            'fallback' => 'ONSITE_SIGNIN_LEAD_MINUTES',
            'default'  => 45,
        ],
        'signin_grace' => [
            'label'    => 'Stays open after end (min)',
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

    public static function lead(array $session, string $mode): int
    {
        $own = $session['signin_lead_minutes'] ?? null;

        return $own !== null && $own !== ''
            ? (int) $own
            : self::get($mode === 'onsite' ? 'signin_lead_onsite' : 'signin_lead_virtual');
    }

    public static function save(array $input, ?int $staffId): void
    {
        $values = [];
        $errors = [];

        foreach (self::RULES as $key => $rule) {
            $n = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);

            if ($n === false || $n < $rule['min'] || $n > $rule['max']) {
                $errors[] = "{$rule['label']}: enter a whole number from {$rule['min']} to {$rule['max']}.";
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
            'Sign-in defaults changed by staff %d: %s -> %s',
            (int) $staffId,
            json_encode($before),
            json_encode($values)
        ));
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
