<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;

/**
 * A registry with the panel points of the core's Panel fixture, compiled as cms:build compiles
 * them, for the commands that inspect the panel. The registry holds no contribution until addon
 * manifests declare them, so withFills() gives the slot notes.detail.sections@1 three, in another
 * order than the host renders them.
 */
final class PanelRegistry
{
    public static function bind(bool $withFills = false): CompiledRegistry
    {
        $compiled = app(RegistryCompiler::class)->compile(app(DeclarationScanner::class)->scan(new ScanRoots(RegistryFixtures::root('Panel'))));
        $registry = $withFills ? self::withFills($compiled) : $compiled;
        $cache = new FakeRegistryCache;
        $cache->write($registry);
        app()->instance(RegistryCache::class, $cache);
        app()->instance(CompiledRegistry::class, $registry);

        return $registry;
    }

    private static function withFills(CompiledRegistry $registry): CompiledRegistry
    {
        $fills = [
            new PanelFill(new ContributionId('reviews.stars'), 'acme/cms-reviews', 500, new Scope(
                [new PageName('notes.detail')],
                [new CommandRef(new CommandName('note.create'), 1)],
                [new TypeName('app:note')],
                ['reviews:stars'],
                new CommandName('note.find'),
            )),
            new PanelFill(new ContributionId('approvals.badge'), 'acme/cms-approvals', 500, Scope::everywhere()),
            new PanelFill(new ContributionId('cms.summary'), 'cboxdk/cms', 100, Scope::everywhere()),
        ];

        return new CompiledRegistry(
            $registry->commands,
            $registry->hooks,
            $registry->actions,
            $registry->subscribers,
            $registry->schema,
            $registry->rest,
            array_map(
                static fn (PanelPointEntry $point): PanelPointEntry => $point->id()->toString() === 'notes.detail.sections@1'
                    ? new PanelPointEntry($point->declaration, $point->class, $point->package, $point->stability, $fills)
                    : $point,
                $registry->panel,
            ),
        );
    }
}
