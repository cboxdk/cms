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
 * Reads lc_messages on the doctor's copy of the app role's connection and on its copy of the
 * owner role's connection, and LC_MESSAGES of this process with setlocale(LC_MESSAGES, '0'),
 * which only reads.
 */
#[Internal]
final readonly class ConnectionLcMessagesProbe implements LcMessagesProbe
{
    public function __construct(
        private DoctorConnection $app,
        private DoctorConnection $owner,
    ) {}

    #[Override]
    public function appRole(): RoleLcMessages
    {
        return $this->read($this->app);
    }

    #[Override]
    public function ownerRole(): RoleLcMessages
    {
        return $this->read($this->owner);
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

    private function read(DoctorConnection $connection): RoleLcMessages
    {
        try {
            $row = CatalogRow::one($connection->rows(
                "select current_user::text as role, s.setting::text as value, s.source::text as source from pg_settings s where s.name = 'lc_messages'",
            ));
        } catch (ProbeFailed $failed) {
            throw $failed->at('On the connection '.$connection->source);
        }

        return new RoleLcMessages($row->string('role'), $connection->source, $row->string('value'), $row->string('source'));
    }
}
