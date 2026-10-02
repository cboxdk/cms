<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Adapter\RegistryPermissionCatalog;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RegistryPermissionCatalog against PermissionCatalogBehaviour, over a compiled registry built in
 * memory: each command registered in versions 1 and 2, and each query with a query action. The
 * entries name the probe's classes, which the catalog does not read.
 */
final class RegistryPermissionCatalogBehaviourTest extends TestCase
{
    use PermissionCatalogBehaviour;

    #[Override]
    protected function catalogOf(array $commands, array $queries): PermissionCatalog
    {
        $entries = [];

        foreach ($commands as $command) {
            foreach ([1, 2] as $version) {
                $entries[] = new CommandEntry(new CommandName($command), $version, RenameProbe::class, 'cboxdk/cms');
            }
        }

        return new RegistryPermissionCatalog(new CompiledRegistry($entries, [], array_map(
            static fn (string $query): ActionEntry => new ActionEntry(RenameProbeAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName($query), 1, RenameProbe::class, []),
            $queries,
        )));
    }
}
