<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Bindings\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * The configuration binds a contract to something that is not an instantiable class
 * implementing it. A deploy error, raised when the contract is first resolved.
 */
#[Internal]
final class InvalidContractBinding extends LogicException {}
