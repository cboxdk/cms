<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Surfaces;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;

/*
 * The panel and REST stay in parity (decided by Sylvester on 29 September 2026): every action the
 * Inertia profile exposes is on REST too, because the mobile and desktop apps build on REST. The
 * registry is compiled here from the scan roots and addon manifests of the installation's service
 * providers, as cms:build compiles it, not read from a cache that may be stale, so an action
 * declared with Surface::Inertia and without Surface::Rest anywhere in a scan root fails this test.
 * InertiaActionsTest shows the refusal with a planted action.
 */

it('exposes no action on Inertia that is not on REST', function (): void {
    $registry = app(RegistryCompiler::class)->compile(
        app(DeclarationScanner::class)->scan(ProviderScanRoots::of(app())),
        ProviderAddonManifests::of(app()),
    );

    $broken = array_values(array_map(
        static fn (ActionEntry $entry): string => sprintf('%s (%s version %d)', $entry->class, $entry->command->value, $entry->commandVersion),
        array_filter($registry->actions, static fn (ActionEntry $entry): bool => $entry->exposes(Surface::Inertia) && ! $entry->exposes(Surface::Rest)),
    ));

    expect($broken)->toBe([])
        ->and(new InertiaActions($registry))->toBeInstanceOf(InertiaActions::class);
});
