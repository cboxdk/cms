<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The result of a Postgres check whose query failed after postgres.reachable passed, for example
 * because the server went away in between.
 */
#[Internal]
final readonly class PostgresQueryFailure
{
    public const string CODE = 'doctor_postgres_query_failed';

    public static function result(CheckId $id, bool $blocking, string $what, ProbeFailed $failed): CheckResult
    {
        return CheckResult::fail(
            $id,
            $blocking,
            $failed->kind,
            self::CODE,
            sprintf('The doctor could not read %s from Postgres.', $what),
            $failed->cause,
            'Check that Postgres is running and that the app role may read the system catalogs, then run cms:doctor again.',
        );
    }
}
