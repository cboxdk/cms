<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * Thrown by MakePanelInput for arguments cms:make:panel does not take: a usage error, exit 64.
 */
#[Internal]
final class MakePanelRefused extends InvalidArgumentException {}
