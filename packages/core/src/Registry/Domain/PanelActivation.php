<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;

/**
 * Where the panel reads the activation state of its contributions at run time (PRD 13.5, 13.4),
 * without a rebuild. The core binds it to Adapter\ConfigPanelActivation, which reads
 * cbox-cms.panel.disabled at each call.
 */
#[Internal]
interface PanelActivation
{
    /**
     * @throws InvalidPanelActivation when the activation state cannot be read
     */
    public function disabled(): DisabledContributions;
}
