<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The installation operator (PRD 5.16, 3.3): the service actor cms:install created once, which the
 * maintenance commands run as. Its id lives in the kernel table `installation`, never in .env or
 * the configuration, so every process and every deploy finds the same one.
 */
#[Internal]
interface InstallationOperator
{
    /**
     * The operator's id, or null before cms:install has run.
     */
    public function find(): ?ActorId;
}
