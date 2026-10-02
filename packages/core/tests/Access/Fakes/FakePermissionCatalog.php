<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Override;

/**
 * The PermissionCatalog in memory: the command and query names a test gives
 * (PermissionCatalogBehaviour holds it to RegistryPermissionCatalog).
 */
final readonly class FakePermissionCatalog implements PermissionCatalog
{
    /** @var array<string, true> */
    private array $names;

    /**
     * @param  list<string>  $names
     */
    public function __construct(array $names)
    {
        $this->names = array_fill_keys($names, true);
    }

    #[Override]
    public function has(CommandName $name): bool
    {
        return isset($this->names[$name->value]);
    }
}
