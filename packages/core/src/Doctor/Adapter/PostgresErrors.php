<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Sorts a failed connection or query into unavailable or violation.
 *
 * pdo_pgsql reports every failed connection as SQLSTATE 08006, so the text decides. A refusal
 * from the server itself starts with "FATAL:": a wrong password, an unknown role or database is a
 * violation, while a server that is starting, stopping, recovering or full is unavailable. A
 * failure before the server answered, such as a refused TCP connection or a timeout, is
 * unavailable. The server's messages are matched in English: English messages are part of the
 * operating contract (PRD 4.2), and cms:doctor checks them in postgres.lc_messages.
 */
#[Internal]
final readonly class PostgresErrors
{
    private const string TEMPORARY = '/the database system is (starting up|shutting down|in recovery mode|not yet accepting connections|not accepting connections)|too many clients|remaining connection slots are reserved/i';

    public static function classify(Throwable $thrown): ProbeFailed
    {
        if ($thrown instanceof ProbeFailed) {
            return $thrown;
        }

        $message = self::message($thrown);

        if (str_contains($message, 'FATAL:') && preg_match(self::TEMPORARY, $message) !== 1) {
            return ProbeFailed::violation($message, $thrown);
        }

        return ProbeFailed::unavailable($message, $thrown);
    }

    /**
     * The driver's message, without Laravel's suffix that repeats the SQL. QueryException is a
     * PDOException too, so the driver's own is the one below it.
     */
    private static function message(Throwable $thrown): string
    {
        for ($current = $thrown; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && ! $current instanceof QueryException) {
                return self::oneLine($current->getMessage());
            }
        }

        return self::oneLine($thrown->getMessage());
    }

    private static function oneLine(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $message));

        return $line === '' ? 'The driver gave no message.' : $line;
    }
}
