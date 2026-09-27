<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleLcMessages;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Override;

/**
 * Reads lc_messages on the doctor's copy of the app role's connection, and LC_MESSAGES of this
 * process with setlocale(LC_MESSAGES, '0'), which only reads.
 *
 * The owner role's lc_messages is read on the same connection from pg_db_role_setting, which every
 * role may read, and never by logging in as the owner role: the process that runs the doctor does
 * not need the owner's credentials (PRD 4.2). A new session of the owner role in this database
 * takes the first of its setting for this database (ALTER ROLE ... IN DATABASE), its own setting
 * (ALTER ROLE), the database's (ALTER DATABASE) and the setting for all roles (ALTER ROLE ALL), and
 * otherwise the server's default. The server's default is known when the app role's own session
 * has it, that is when the app role's value comes from the configuration file, the command line,
 * the environment or the built-in default; otherwise a session cannot read it without superuser,
 * and the probe says so.
 */
#[Internal]
final readonly class ConnectionLcMessagesProbe implements LcMessagesProbe
{
    private const string OWNER_SQL = <<<'SQL'
        select r.rolname::text as role, l.value, l.source, a.setting::text as session_value, a.source::text as session_source
        from pg_roles r
        cross join pg_database d
        cross join pg_settings a
        left join lateral (
            select substr(c.entry, length('lc_messages=') + 1) as value,
                case
                    when s.setrole <> 0 and s.setdatabase <> 0 then 'database user'
                    when s.setrole <> 0 then 'user'
                    when s.setdatabase <> 0 then 'database'
                    else 'global'
                end as source,
                case
                    when s.setrole <> 0 and s.setdatabase <> 0 then 1
                    when s.setrole <> 0 then 2
                    when s.setdatabase <> 0 then 3
                    else 4
                end as rank
            from pg_db_role_setting s
            cross join lateral unnest(s.setconfig) as c(entry)
            where s.setrole in (r.oid, 0)
              and s.setdatabase in (d.oid, 0)
              and starts_with(c.entry, 'lc_messages=')
            order by rank
            limit 1
        ) l on true
        where r.rolname = ?
          and d.datname = current_database()
          and a.name = 'lc_messages'
        SQL;

    /**
     * @param  ?string  $ownerRole  the owner role's name, or null when the configuration names none
     */
    public function __construct(
        private DoctorConnection $app,
        private ?string $ownerRole,
    ) {}

    #[Override]
    public function appRole(): RoleLcMessages
    {
        try {
            $row = CatalogRow::one($this->app->rows(
                "select current_user::text as role, s.setting::text as value, s.source::text as source from pg_settings s where s.name = 'lc_messages'",
            ));
        } catch (ProbeFailed $failed) {
            throw $failed->at('On the connection '.$this->app->source);
        }

        return new RoleLcMessages(
            $row->string('role'),
            $this->app->source,
            $row->string('value'),
            SettingSourceParser::parse('lc_messages', $row->string('source')),
        );
    }

    #[Override]
    public function ownerRole(): RoleLcMessages
    {
        if ($this->ownerRole === null) {
            throw ProbeFailed::violation('The owner role is not known: cms.doctor.owner_role is null and this process has no owner connection with a username.');
        }

        try {
            $rows = CatalogRow::all($this->app->rows(self::OWNER_SQL, [$this->ownerRole]));
        } catch (ProbeFailed $failed) {
            throw $failed->at('On the connection '.$this->app->source);
        }

        if ($rows === []) {
            throw ProbeFailed::violation(sprintf('The owner role %s does not exist in the database of the connection %s.', $this->ownerRole, $this->app->source));
        }

        $row = $rows[0];
        $value = $row->nullableString('value');
        $source = $row->nullableString('source');

        if ($value !== null && $source !== null) {
            return new RoleLcMessages($row->string('role'), $this->app->source, $value, SettingSourceParser::parse('lc_messages', $source));
        }

        $sessionSource = SettingSourceParser::parse('lc_messages', $row->string('session_source'));

        if (! $sessionSource->isServer()) {
            throw ProbeFailed::violation(sprintf(
                'The owner role %s has no lc_messages of its own, of the database or of ALTER ROLE ALL, so it gets the server\'s default, which the app role cannot read: its own lc_messages comes from "%s".',
                $row->string('role'),
                $sessionSource->value,
            ));
        }

        return new RoleLcMessages($row->string('role'), $this->app->source, $row->string('session_value'), $sessionSource);
    }

    #[Override]
    public function process(): string
    {
        $locale = setlocale(LC_MESSAGES, '0');

        if ($locale === false) {
            throw ProbeFailed::violation('PHP could not read the LC_MESSAGES category of the process locale.');
        }

        return $locale;
    }
}
