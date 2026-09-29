<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Override;

/**
 * The Postgres extensions the core's tables need are installed in the database (PRD 4.2, 5.8):
 * ltree, whose paths hold the node tree and which the row level security of the content tables
 * tests. The core's migrations create them as the owner role; they are trusted extensions, so the
 * owner role needs no superuser, only CREATE on the database, which owning it gives.
 */
#[Internal]
final readonly class ExtensionsCheck implements DoctorCheck
{
    public const string ID = 'postgres.extensions';

    public const string CODE = 'doctor_extension_missing';

    /** @var list<string> The extensions the core needs, sorted. */
    public const array REQUIRED = ['ltree'];

    public function __construct(private PostgresProbe $postgres) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return true;
    }

    #[Override]
    public function requires(): array
    {
        return [new CheckId(PostgresReachableCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $extensions = $this->postgres->extensions();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the installed extensions', $failed);
        }

        $missing = array_values(array_diff(self::REQUIRED, $extensions->names));

        if ($missing === []) {
            return CheckResult::pass($this->id(), true, sprintf(
                'The database %s has the extensions the core needs: %s.',
                $extensions->database,
                implode(', ', self::REQUIRED),
            ));
        }

        $names = implode(', ', $missing);

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'A Postgres extension the core\'s tables need is not installed in the database, so the migrations have not run, or ran against another database. The core\'s migrations create it as the owner role.',
            sprintf('The database %s lacks the extensions %s.', $extensions->database, $names),
            sprintf(
                'Run the migrations as the owner role in the maintenance process, php artisan migrate --database=<the connection cbox-cms.database.owner_connection names>, on a server that has the extensions available (pg_available_extensions lists %s), then run cms:doctor again.',
                $names,
            ),
        );
    }
}
