<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleLcMessages;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Override;

/**
 * Server and client messages are English (PRD 4.2). The kernel reads the text of some Postgres
 * errors, because the SQLSTATE alone does not tell them apart: MissingPartitionMapper tells a
 * missing partition from a broken CHECK by "no partition of relation", and the doctor tells a
 * refused login from a server that is starting by the "FATAL:" message. In another language the
 * first becomes a CHECK violation and the second a dependency to wait for.
 *
 * It reads lc_messages of the app role in a new session, lc_messages that a new session of the
 * owner role gets, which the probe reads from the catalog as the app role so the process needs no
 * owner credentials (PRD 4.2), and LC_MESSAGES of the PHP process, which libpq's own messages
 * follow. Each must be C, POSIX, C with a character set such as C.UTF-8, or an English locale,
 * en_*. A failed login is reported before the role's settings apply, in the server's default,
 * which a session cannot read without superuser; the fix sets that default too.
 */
#[Internal]
final readonly class LcMessagesCheck implements DoctorCheck
{
    public const string ID = 'postgres.lc_messages';

    public const string CODE = 'doctor_lc_messages_not_english';

    public function __construct(private LcMessagesProbe $messages) {}

    /**
     * Whether messages in the locale are English: C and POSIX are untranslated, C.<charset> is C
     * with another character set, and en_* is English.
     */
    public static function isEnglish(string $locale): bool
    {
        return $locale === 'C'
            || $locale === 'POSIX'
            || str_starts_with($locale, 'C.')
            || str_starts_with($locale, 'en_');
    }

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
            $roles = [$this->messages->appRole(), $this->messages->ownerRole()];
            $process = $this->messages->process();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                true,
                $failed->kind,
                PostgresQueryFailure::CODE,
                'The doctor could not read the language of the messages from Postgres or from the PHP process.',
                $failed->cause,
                'Check that Postgres is running and that the app role\'s connection is configured and may log in, and that cbox-cms.doctor.owner_role, or the username of the owner connection, names the owner role. When the doctor cannot read the server\'s default, give the owner role a value of its own: as a superuser, run ALTER ROLE <owner role> SET lc_messages = \'C\'. Then run cms:doctor again.',
            );
        }

        $foreign = array_values(array_filter($roles, static fn (RoleLcMessages $role): bool => ! self::isEnglish($role->value)));
        $processForeign = ! self::isEnglish($process);

        if ($foreign === [] && ! $processForeign) {
            return CheckResult::pass($this->id(), true, sprintf(
                'Messages are English: lc_messages is %s for the role %s and %s for the role %s, and LC_MESSAGES of the PHP process is %s.',
                $roles[0]->value,
                $roles[0]->role,
                $roles[1]->value,
                $roles[1]->role,
                $process,
            ));
        }

        $causes = array_map(
            static fn (RoleLcMessages $role): string => sprintf('lc_messages is \'%s\' for the role %s, read on the connection %s; Postgres takes it from "%s".', $role->value, $role->role, $role->connection, $role->source->value),
            $foreign,
        );
        $fixes = [];

        if ($foreign !== []) {
            $alterRoles = array_unique(array_map(static fn (RoleLcMessages $role): string => sprintf("ALTER ROLE %s SET lc_messages = 'C'", $role->role), $foreign));
            $fixes[] = sprintf(
                "As a superuser, run ALTER SYSTEM SET lc_messages = 'C' and SELECT pg_reload_conf(), so errors from before a login are English too, and %s. A value set with ALTER ROLE ... IN DATABASE wins over both; reset it there.",
                implode(' and ', $alterRoles),
            );
        }

        if ($processForeign) {
            $causes[] = sprintf('LC_MESSAGES of the PHP process is \'%s\'.', $process);
            $fixes[] = "Give the PHP process an English message locale: remove the setlocale() call that sets LC_MESSAGES or LC_ALL, or call setlocale(LC_MESSAGES, 'C') after it, and start PHP with LC_ALL and LC_MESSAGES unset, C or en_*.";
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Postgres or libpq writes its messages in another language than English. The kernel reads the text of some errors, so a missing partition would not be recognised, and a refused login would look like a server that is starting.',
            implode(' ', $causes),
            implode(' ', $fixes),
        );
    }
}
