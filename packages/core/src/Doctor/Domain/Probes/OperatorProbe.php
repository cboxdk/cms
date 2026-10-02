<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The installation operator (PRD 5.16), read as the app role on the primary: the actor the kernel
 * table `installation` names.
 */
#[Internal]
interface OperatorProbe
{
    /**
     * The operator as an actor, or null when the installation has none yet.
     *
     * @throws ProbeFailed when the installation or the actor cannot be read
     */
    public function operator(): ?Actor;
}
