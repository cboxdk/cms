<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\ScanRoot;

/**
 * A scan root with its directory resolved to a real path.
 */
#[Internal]
final readonly class ResolvedRoot
{
    public function __construct(
        public ScanRoot $root,
        public string $directory,
    ) {}

    public function describe(): string
    {
        return sprintf('%s (%s)', $this->directory, $this->root->package);
    }
}
