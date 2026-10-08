<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Cli\CliServiceProvider;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Actions\ListGrantsAction;
use Cbox\Cms\Core\Access\Actions\ListRolesAction;
use Cbox\Cms\Core\Access\Actions\RevokeGrantAction;
use Cbox\Cms\Core\Access\Actions\SetRolePermissionsAction;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Actions\ReleaseVariantAction;
use Cbox\Cms\Core\Entries\Actions\ReviseEntryAction;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Identity\Actions\ActivateActorAction;
use Cbox\Cms\Core\Identity\Actions\DeactivateActorAction;
use Cbox\Cms\Core\Identity\Actions\ListActorsAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\GrantBootstrapRoleAction;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Placements\Actions\CreatePlacementAction;
use Cbox\Cms\Core\Placements\Actions\SetPlacementWindowAction;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Actions\ListActionsAction;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Seeding\Actions\SeedEntriesAction;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Structure\Actions\ArchiveNodeAction;
use Cbox\Cms\Core\Structure\Actions\CreateNodeAction;
use Cbox\Cms\Core\Structure\Actions\ListNodesAction;
use Cbox\Cms\Core\Structure\Actions\RegisterSiteAction;
use Cbox\Cms\Core\Structure\Actions\SetNodeRouteAction;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Tests\Registry\Providers\DeferredRootProvider;
use Cbox\Cms\Core\Tests\Registry\Providers\FixtureRootProvider;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Cbox\Cms\Http\HttpServiceProvider;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Panel\PanelServiceProvider;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * The scan roots of the four module providers of cboxdk/cms, which every application has: one
 * package, one scan root per module's src.
 */
function packageScanRoots(): ScanRoots
{
    $packages = dirname(__DIR__, 3);

    return new ScanRoots(
        new ScanRoot('cboxdk/cms', $packages.'/core/src'),
        new ScanRoot('cboxdk/cms', $packages.'/http/src'),
        new ScanRoot('cboxdk/cms', $packages.'/cli/src'),
        new ScanRoot('cboxdk/cms', $packages.'/generators/src'),
        new ScanRoot('cboxdk/cms', $packages.'/mcp/src'),
        new ScanRoot('cboxdk/cms', $packages.'/identity/src'),
        new ScanRoot('cboxdk/cms', $packages.'/panel/src'),
    );
}

it('lets each package provider declare its own src directory as a scan root', function (): void {
    $packages = dirname(__DIR__, 3);

    expect(new CoreServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(CoreServiceProvider::PACKAGE, $packages.'/core/src')])
        ->and(new HttpServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(HttpServiceProvider::PACKAGE, $packages.'/http/src')])
        ->and(new CliServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(CliServiceProvider::PACKAGE, $packages.'/cli/src')])
        ->and(new GeneratorsServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(GeneratorsServiceProvider::PACKAGE, $packages.'/generators/src')])
        ->and(new IdentityServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(IdentityServiceProvider::PACKAGE, $packages.'/identity/src')])
        ->and(new PanelServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(PanelServiceProvider::PACKAGE, $packages.'/panel/src')]);
});

it('collects the scan roots of every registered provider that declares them', function (): void {
    $roots = ProviderScanRoots::of(app())->roots;

    // The module providers of cboxdk/cms, and the workbench's fixture addon, which package
    // discovery registers.
    expect($roots)->toHaveCount(8)
        ->and($roots)->toEqualCanonicalizing([...packageScanRoots()->roots, new ScanRoot(FixtureAddonServiceProvider::PACKAGE, dirname(__DIR__, 4).'/workbench/addons/fixtureaddon/src')]);

    app()->register(FixtureRootProvider::class);

    expect(ProviderScanRoots::of(app())->roots)->toHaveCount(9)->toContainEqual(RegistryFixtures::root('Valid'));
});

it('registers deferred providers first, so their scan roots are not missed', function (): void {
    app()->addDeferredServices([DeferredRootProvider::SERVICE => DeferredRootProvider::class]);

    expect(app()->getProviders(DeferredRootProvider::class))->toBe([])
        ->and(ProviderScanRoots::of(app())->roots)->toContainEqual(new ScanRoot('acme/deferred', __DIR__.'/Providers'));
});

it('scans the packages\' own classes without a problem and registers the kernel\'s own commands with their actions and its invalidation subscriber', function (): void {
    $registry = RegistryFixtures::builder(RegistryFixtures::scratch())->build(packageScanRoots());

    expect(array_map(static fn (CommandEntry $entry): string => $entry->name->value.'@'.$entry->version.' '.$entry->class, $registry->commands))
        ->toBe([
            'access.bootstrap@1 '.GrantBootstrapRole::class,
            'actor.activate@1 '.ActivateActor::class,
            'actor.deactivate@1 '.DeactivateActor::class,
            'actor.register@1 '.RegisterActor::class,
            'entry.create@1 '.CreateEntry::class,
            'entry.publish@1 '.PublishEntry::class,
            'entry.revise@1 '.ReviseEntry::class,
            'entry.unpublish@1 '.UnpublishEntry::class,
            'grant.assign@1 '.AssignGrant::class,
            'grant.revoke@1 '.RevokeGrant::class,
            'node.archive@1 '.ArchiveNode::class,
            'node.create@1 '.CreateNode::class,
            'node.set_route@1 '.SetNodeRoute::class,
            'placement.create@1 '.CreatePlacement::class,
            'placement.set_window@1 '.SetPlacementWindow::class,
            'role.create@1 '.CreateRole::class,
            'role.set_permissions@1 '.SetRolePermissions::class,
            'seed.entries@1 '.SeedEntries::class,
            'site.register@1 '.RegisterSite::class,
            'variant.release@1 '.ReleaseVariant::class,
        ])
        ->and(array_map(static fn (ActionEntry $entry): string => $entry->command->value.'@'.$entry->commandVersion.' '.$entry->class.' '.$entry->kind->value, $registry->actions))
        ->toBe([
            'access.bootstrap@1 '.GrantBootstrapRoleAction::class.' write',
            'action.list@1 '.ListActionsAction::class.' query',
            'actor.activate@1 '.ActivateActorAction::class.' write',
            'actor.deactivate@1 '.DeactivateActorAction::class.' write',
            'actor.list@1 '.ListActorsAction::class.' query',
            'actor.me@1 '.WhoAmIAction::class.' query',
            'actor.register@1 '.RegisterActorAction::class.' write',
            'entry.create@1 '.CreateEntryAction::class.' write',
            'entry.publish@1 '.PublishEntryAction::class.' write',
            'entry.revise@1 '.ReviseEntryAction::class.' write',
            'entry.unpublish@1 '.UnpublishEntryAction::class.' write',
            'grant.assign@1 '.AssignGrantAction::class.' write',
            'grant.list@1 '.ListGrantsAction::class.' query',
            'grant.revoke@1 '.RevokeGrantAction::class.' write',
            'node.archive@1 '.ArchiveNodeAction::class.' write',
            'node.create@1 '.CreateNodeAction::class.' write',
            'node.list@1 '.ListNodesAction::class.' query',
            'node.set_route@1 '.SetNodeRouteAction::class.' write',
            'path.resolve@1 '.ResolvePathAction::class.' query',
            'placement.create@1 '.CreatePlacementAction::class.' write',
            'placement.set_window@1 '.SetPlacementWindowAction::class.' write',
            'role.create@1 '.CreateRoleAction::class.' write',
            'role.list@1 '.ListRolesAction::class.' query',
            'role.set_permissions@1 '.SetRolePermissionsAction::class.' write',
            'seed.entries@1 '.SeedEntriesAction::class.' write',
            'site.register@1 '.RegisterSiteAction::class.' write',
            'variant.release@1 '.ReleaseVariantAction::class.' write',
        ])
        ->and($registry->actionFor(DeactivateActor::class)?->surfaces)->toBe([])
        ->and($registry->actionFor(ActivateActor::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Cli])
        ->and($registry->actionFor(RegisterActor::class)?->surfaces)->toBe([Surface::Cli])
        ->and($registry->actionFor(AssignGrant::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Cli])
        ->and($registry->actionFor(RevokeGrant::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Cli])
        ->and($registry->actionFor(CreateRole::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Cli])
        ->and($registry->actionFor(SetRolePermissions::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Cli])
        ->and($registry->actionFor(RegisterSite::class)?->surfaces)->toBe([])
        ->and($registry->actionFor(GrantBootstrapRole::class)?->surfaces)->toBe([])
        ->and($registry->actionFor(CreateEntry::class)?->class)->toBe(CreateEntryAction::class)
        ->and($registry->actionFor(ReviseEntry::class)?->class)->toBe(ReviseEntryAction::class)
        ->and($registry->actionFor(ListNodes::class)?->surfaces)->toBe([Surface::Rest, Surface::Inertia])
        ->and(array_map($registry->count(...), RegistryName::cases()))->toBe([27, 0, 20, 0, 15, 21, 0, 1])
        ->and($registry->hooks)->toBe([])
        ->and(array_map(static fn (SubscriberEntry $entry): string => $entry->name->value.' '.$entry->class.' '.$entry->lane->value.' '.$entry->projection?->value, $registry->subscribers))
        ->toBe(['fragments.invalidate '.InvalidateFragments::class.' critical origin'])
        ->and($registry->schema)->toBe([]);
});

it('binds the scanner and a cache in the application\'s bootstrap/cache/cms', function (): void {
    expect(app(DeclarationScanner::class))->toBeInstanceOf(AttributeScanner::class)
        ->and(app(RegistryCache::class))->toBeInstanceOf(FileRegistryCache::class)
        ->and(app(RegistryCache::class)->location())->toBe(app()->bootstrapPath('cache/cms'))
        ->and(app(BuildRegistry::class)->location())->toBe(app()->bootstrapPath('cache/cms'))
        ->and(app(OpenApiDocuments::class))->toBeInstanceOf(FileOpenApiDocuments::class);
});

it('loads the registry at run time from the files cms:build wrote, once per process', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->instance(OpenApiDocuments::class, RegistryFixtures::documents($directory));
    app()->forgetInstance(CompiledRegistry::class);

    $built = app(BuildRegistry::class)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(app(CompiledRegistry::class))->toEqual($built)
        ->and(app(CompiledRegistry::class))->toBe(app(CompiledRegistry::class));
});
