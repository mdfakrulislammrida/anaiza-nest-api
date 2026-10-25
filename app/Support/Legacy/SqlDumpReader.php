<?php

namespace App\Support\Legacy;

/**
 * Reads the INSERT statements of a MySQL / phpMyAdmin dump (the old site's export) into rows, without a database: the old
 * site's tables never have to be loaded anywhere. Only plain `INSERT INTO `table` (`columns`) VALUES (...),(...);` statements
 * are understood, which is what a phpMyAdmin export writes. Values are strings (with MySQL's escapes undone), integers, floats or null.
 */
final class SqlDumpReader
{
    /**
     * @return array<string, list<array<string, string|int|float|null>>> table name => rows, each row keyed by column name
     */
    public static function rows(string $sql): array
    {
        $tables = [];
        $length = strlen($sql);
        $offset = 0;

        while (preg_match('/INSERT\s+INTO\s+`([^`]+)`\s*\(([^)]*)\)\s*VALUES\s*/i', $sql, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $table = $match[1][0];
            $columns = array_map(fn (string $name): string => trim($name, " `\t\n\r"), explode(',', $match[2][0]));
            $position = $match[0][1] + strlen($match[0][0]);

            while ($position < $length) {
                [$values, $position] = self::tuple($sql, $position);
                $tables[$table][] = array_combine($columns, array_pad($values, count($columns), null)) ?: [];

                // After a row: a comma (another row), or the semicolon that ends the statement.
                while ($position < $length && ctype_space($sql[$position])) {
                    $position++;
                }

                if ($position < $length && $sql[$position] === ',') {
                    $position++;
                    while ($position < $length && ctype_space($sql[$position])) {
                        $position++;
                    }

                    continue;
                }

                break;
            }

            $offset = $position;
        }

        return $tables;
    }

    /**
     * The column names and types of each CREATE TABLE, so a file can be inspected without reading any of its rows.
     *
     * @return array<string, array<string, string>> table => column => type
     */
    public static function columns(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE/is', $sql, $found, PREG_SET_ORDER)) {
            foreach ($found as $table) {
                foreach (preg_split('/\R/', $table[2]) ?: [] as $line) {
                    if (preg_match('/^\s*`([^`]+)`\s+([a-z]+(?:\([^)]*\))?(?:\s+unsigned)?)/i', $line, $column)) {
                        $tables[$table[1]][$column[1]] = strtolower($column[2]);
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * One parenthesised row starting at $position (at its "("). Returns its values and the position after its ")".
     *
     * @return array{0: list<string|int|float|null>, 1: int}
     */
    private static function tuple(string $sql, int $position): array
    {
        $length = strlen($sql);

        if (($sql[$position] ?? '') !== '(') {
            return [[], $length];
        }

        $position++;
        $values = [];

        while ($position < $length) {
            while ($position < $length && ctype_space($sql[$position])) {
                $position++;
            }

            if ($sql[$position] === "'") {
                [$text, $position] = self::quoted($sql, $position);
                $values[] = $text;
            } else {
                $start = $position;
                while ($position < $length && $sql[$position] !== ',' && $sql[$position] !== ')') {
                    $position++;
                }
                $raw = trim(substr($sql, $start, $position - $start));
                $values[] = strcasecmp($raw, 'NULL') === 0 ? null : (is_numeric($raw) ? (str_contains($raw, '.') ? (float) $raw : (int) $raw) : $raw);
            }

            while ($position < $length && ctype_space($sql[$position])) {
                $position++;
            }

            if ($sql[$position] === ',') {
                $position++;

                continue;
            }

            // The closing parenthesis.
            return [$values, $position + 1];
        }

        return [$values, $position];
    }

    /**
     * A single-quoted string at $position (at its opening quote), with MySQL's escapes undone.
     *
     * @return array{0: string, 1: int}
     */
    private static function quoted(string $sql, int $position): array
    {
        $length = strlen($sql);
        $position++;
        $text = '';

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '\\' && $position + 1 < $length) {
                $next = $sql[$position + 1];
                $text .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    'Z' => "\x1a",
                    default => $next,
                };
                $position += 2;

                continue;
            }

            if ($char === "'") {
                if (($sql[$position + 1] ?? '') === "'") {
                    $text .= "'";
                    $position += 2;

                    continue;
                }

                return [$text, $position + 1];
            }

            $text .= $char;
            $position++;
        }

        return [$text, $position];
    }
}
