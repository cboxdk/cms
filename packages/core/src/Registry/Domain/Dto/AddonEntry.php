<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An installed addon in addons.php (PRD 13.1, 13.4), from its manifest: its namespace and
 * package, the core API version it needs, its capabilities (the classification the kernel hands
 * it, the commands its panel UI may issue, sorted by command, and whether it ships a theme), and
 * its panel contributions beyond the fills, or null for an addon without UI. The install screen
 * shows them, and the panel encodes a contribution's props at the lower of the viewer's access
 * and reads.
 */
#[Experimental]
final readonly class AddonEntry
{
    public string $package;

    /** @var list<IssuedCommand> */
    public array $issues;

    /**
     * @param  list<IssuedCommand>  $issues
     */
    public function __construct(
        public AddonNamespace $namespace,
        string $package,
        public CoreApiVersion $coreApi,
        public ClassificationAccess $reads,
        array $issues,
        public bool $uiTheme,
        public ?AddonPanel $panel = null,
    ) {
        $this->package = InvalidRegistryEntry::checkPackage($package);
        usort($issues, static fn (IssuedCommand $a, IssuedCommand $b): int => [$a->command->name->value, $a->command->version] <=> [$b->command->name->value, $b->command->version]);
        $this->issues = $issues;
    }
}
