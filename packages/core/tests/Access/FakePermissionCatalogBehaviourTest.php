<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissionCatalog;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * FakePermissionCatalog against PermissionCatalogBehaviour.
 */
final class FakePermissionCatalogBehaviourTest extends TestCase
{
    use PermissionCatalogBehaviour;

    #[Override]
    protected function catalogOf(array $commands, array $queries): PermissionCatalog
    {
        return new FakePermissionCatalog([...$commands, ...$queries]);
    }
}
