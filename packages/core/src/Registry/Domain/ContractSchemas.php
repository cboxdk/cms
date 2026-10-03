<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;

/**
 * Where cms:build reads the JSON Schemas of the commands, queries and panel points it checks the
 * panel's contributions against (PRD 13.4). The port of BuildRegistry; the core binds it to
 * Adapter\CodecContractSchemas.
 */
#[Internal]
interface ContractSchemas
{
    /**
     * @throws RegistryBuildFailed when a schema cannot be read
     */
    public function shapes(): ContractShapes;
}
