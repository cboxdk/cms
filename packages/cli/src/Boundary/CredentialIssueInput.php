<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Core\Maintenance\Domain\Dto\CredentialIssue;
use DateInterval;
use DateTimeImmutable;

/**
 * Reads what cms:credential:issue was given into a CredentialIssue: the argument actor, the UUIDv7
 * of the service actor; --ceiling, the classification ceiling of the credential, one of the
 * classification levels, internal unless given; and --days, how many days from now the credential
 * lives, 1 to MAX_DAYS, DEFAULT_DAYS unless given. Anything else is a usage error, exit 64.
 */
#[Internal]
final readonly class CredentialIssueInput
{
    public const int DEFAULT_DAYS = 90;

    public const int MAX_DAYS = 365;

    public const string DEFAULT_CEILING = 'internal';

    /**
     * @throws CliCallRefused
     */
    public static function read(mixed $actor, mixed $ceiling, mixed $days, DateTimeImmutable $now): CredentialIssue
    {
        try {
            $id = ActorId::fromString(is_string($actor) ? $actor : '');
        } catch (InvalidUuid7 $invalid) {
            throw CliCallRefused::usage('Give the UUIDv7 of an active service actor: cms:credential:issue <actor> [--ceiling=<level>] [--days=<1 to 365>].', $invalid);
        }

        $level = ClassificationAccess::tryFrom(is_string($ceiling) ? $ceiling : '')
            ?? throw CliCallRefused::usage(sprintf('--ceiling: one of %s.', implode(', ', array_map(static fn (ClassificationAccess $access): string => $access->value, ClassificationAccess::cases()))));

        if (! is_string($days) || preg_match('/\A[1-9][0-9]{0,2}\z/', $days) !== 1 || (int) $days > self::MAX_DAYS) {
            throw CliCallRefused::usage(sprintf('--days: a whole number of days from 1 to %d.', self::MAX_DAYS));
        }

        return new CredentialIssue($id, $level, $now->add(new DateInterval(sprintf('P%dD', (int) $days))));
    }
}
