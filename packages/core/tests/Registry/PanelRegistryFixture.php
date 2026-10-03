<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;

/**
 * The registry of the Panel fixture for the actions that inspect the panel: its five points, with
 * two contributions to notes.detail.sections@1 given in the reverse of their render order.
 */
final class PanelRegistryFixture
{
    public static function compiled(): CompiledRegistry
    {
        $registry = new RegistryCompiler()->compile(new AttributeScanner()->scan(new ScanRoots(RegistryFixtures::root('Panel'))));
        $fills = [
            new PanelFill(new SlotFill(new ContributionId('reviews.stars'), 'notes.detail.sections@1', priority: 200, scope: Scope::everywhere()), 'acme/cms-reviews', 200),
            new PanelFill(new SlotFill(new ContributionId('cms.summary'), 'notes.detail.sections@1', priority: 100, scope: Scope::everywhere()), 'cboxdk/cms', 100),
        ];

        return new CompiledRegistry([], [], panel: array_map(
            static fn (PanelPointEntry $point): PanelPointEntry => $point->id()->toString() === 'notes.detail.sections@1'
                ? new PanelPointEntry($point->declaration, $point->class, $point->package, $point->stability, $fills)
                : $point,
            $registry->panel,
        ));
    }

    public static function cache(): FakeRegistryCache
    {
        $cache = new FakeRegistryCache;
        $cache->write(self::compiled());

        return $cache;
    }
}
