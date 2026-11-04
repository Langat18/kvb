<?php

declare(strict_types=1);

namespace VetBoard;

final class Points
{
    public const DAILY_CAP = 8;
    public const DAILY_CAP_TENTHS = 80;

    // Parses form input with at most one decimal place. Returns tenths of a point, or null if invalid.
    public static function parseTenths(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 10;
        }

        if (is_float($value)) {
            return (int) round($value * 10);
        }

        $text = trim((string) $value);

        if ($text === '') {
            return 0;
        }

        if (!preg_match('/^(?=\d|\.\d)(\d{0,3})(?:\.(\d)0*)?$/', $text, $m)) {
            return null;
        }

        return ((int) $m[1]) * 10 + (int) ($m[2] ?? 0);
    }

    public static function parse(mixed $value): ?float
    {
        $tenths = self::parseTenths($value);

        return $tenths === null ? null : $tenths / 10;
    }

    // Converts a stored value (numeric string or number) to tenths.
    public static function tenths(mixed $value): int
    {
        return (int) round(((float) $value) * 10);
    }

    public static function fromTenths(int $tenths): float
    {
        return $tenths / 10;
    }

    public static function format(mixed $value): string
    {
        $tenths = self::tenths($value);

        return $tenths % 10 === 0
            ? (string) intdiv($tenths, 10)
            : number_format($tenths / 10, 1, '.', '');
    }
}
