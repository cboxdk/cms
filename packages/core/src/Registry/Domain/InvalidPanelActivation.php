<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use UnexpectedValueException;

/**
 * The activation state of the panel's contributions, cbox-cms.panel.disabled, is not of its form:
 * a list of addon namespaces under addons and a list of contribution ids under contributions.
 */
#[Experimental]
final class InvalidPanelActivation extends UnexpectedValueException {}
