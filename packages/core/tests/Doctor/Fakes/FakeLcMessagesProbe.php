<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\RoleLcMessages;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;

/**
 * English messages until the test changes a property: lc_messages C from the role for cms_app on
 * the connection pgsql and for cms_owner on pgsql_owner, and LC_MESSAGES C for the process.
 */
final class FakeLcMessagesProbe implements LcMessagesProbe
{
    public string $appRole = 'C';

    public string $appSource = 'user';

    public string $ownerRole = 'C';

    public string $ownerSource = 'user';

    public string $process = 'C';

    /** Thrown by ownerRole(), as when the owner connection refuses the login. */
    public ?ProbeFailed $ownerFailure = null;

    public function appRole(): RoleLcMessages
    {
        return new RoleLcMessages('cms_app', 'pgsql', $this->appRole, $this->appSource);
    }

    public function ownerRole(): RoleLcMessages
    {
        if ($this->ownerFailure instanceof ProbeFailed) {
            throw $this->ownerFailure;
        }

        return new RoleLcMessages('cms_owner', 'pgsql_owner', $this->ownerRole, $this->ownerSource);
    }

    public function process(): string
    {
        return $this->process;
    }
}
