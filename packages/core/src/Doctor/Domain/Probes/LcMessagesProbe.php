<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleLcMessages;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The language of the messages that the kernel reads the text of (PRD 4.2): lc_messages of the
 * app role and of the owner role, each in a new session on its own connection, and LC_MESSAGES
 * of the PHP process, which libpq's own messages follow.
 */
#[Internal]
interface LcMessagesProbe
{
    /**
     * @throws ProbeFailed
     */
    public function appRole(): RoleLcMessages;

    /**
     * @throws ProbeFailed
     */
    public function ownerRole(): RoleLcMessages;

    /**
     * The LC_MESSAGES category of this process's locale, such as "C".
     *
     * @throws ProbeFailed
     */
    public function process(): string;
}
