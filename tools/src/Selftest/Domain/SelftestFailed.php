<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Domain;

use RuntimeException;

/**
 * The selftest could not set up or read its worktree. A violation a gate missed is a verdict,
 * not this exception.
 */
final class SelftestFailed extends RuntimeException {}
