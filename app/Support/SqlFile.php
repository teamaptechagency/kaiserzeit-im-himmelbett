<?php

namespace App\Support;

use PDO;
use PDOException;

/**
 * Splits and runs a .sql file.
 *
 * Not explode(';'). A semicolon inside a German apartment description would
 * cut a statement in half and quietly corrupt the import, and any real dump
 * is full of escaped quotes. So this walks the text tracking whether it is
 * inside a quoted string, and skips over comments rather than parsing them.
 */
class SqlFile
{
    /** The escape character inside a quoted SQL string. */
    private const ESCAPE = "\x5c";

    /** @return string[] */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $quote      = null;                 // the quote character we are inside, or null
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;

                /* An escaped quote or an escaped escape: take the next
                   character as data, whatever it is. */
                if ($char === self::ESCAPE && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    /* A doubled quote is an escaped quote, not the end of
                       the string. */
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote    = $char;
                $current .= $char;
                continue;
            }

            /* -- and # run to the end of the line */
            if (($char === '-' && $next === '-') || $char === '#') {
                $break    = strpos($sql, "\n", $i);
                $i        = $break === false ? $length : $break;
                $current .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);

                /* mysqldump emits conditional-execution comments that open
                   with an exclamation mark. MySQL runs those as real
                   statements, and dropping one can lose a SET the rest of
                   the dump depends on — so they are kept, not skipped. */
                if (($sql[$i + 2] ?? '') === '!') {
                    $end      = $close === false ? $length : $close + 2;
                    $current .= substr($sql, $i, $end - $i);
                    $i        = $end - 1;
                    continue;
                }

                $i = $close === false ? $length : $close + 1;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Runs every statement in one file.
     *
     * Returns how many succeeded and the first error, if any — the first is
     * the one worth showing; the rest are usually knock-on failures from it.
     *
     * @return array{ran:int,total:int,error:?string}
     */
    public static function run(PDO $pdo, string $path): array
    {
        if (! is_file($path)) {
            return ['ran' => 0, 'total' => 0, 'error' => basename($path) . ' could not be read.'];
        }

        $statements = self::statements((string) file_get_contents($path));
        $ran        = 0;

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $ran++;
            } catch (PDOException $e) {
                return ['ran' => $ran, 'total' => count($statements), 'error' => $e->getMessage()];
            }
        }

        return ['ran' => $ran, 'total' => count($statements), 'error' => null];
    }
}
