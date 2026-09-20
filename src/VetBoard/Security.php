<?php

declare(strict_types=1);

namespace VetBoard;

class Security
{
    // Prevents CSV/Excel formula injection: a value starting with =, +, -,
    // @, tab, or CR could be interpreted as a formula when the file is
    // opened in Excel. Prefixing with an apostrophe forces plain text.
    public static function excelSafe($value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
