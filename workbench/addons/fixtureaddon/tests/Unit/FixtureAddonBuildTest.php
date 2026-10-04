<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireSlugOnRelease;
use Workbench\FixtureAddon\Tests\FixtureAddonTestCase;

/**
 * The fixture addon installed in an application (PRD 13.1 to 13.3): its manifest allows each of
 * its hooks, cms:build compiles the hooks and the schema contribution from the discovered
 * provider, and the application's generated type catalog has the extension field in the addon's
 * namespace.
 */
final class FixtureAddonBuildTest extends FixtureAddonTestCase
{
    #[Test]
    public function its_manifest_allows_each_of_its_hooks(): void
    {
        $manifest = new FixtureAddonServiceProvider(app())->addonManifest();

        foreach ([DeriveSlug::class, RequireSlugOnRelease::class] as $class) {
            $hook = new ReflectionClass($class)->getAttributes(Hook::class)[0]->newInstance();
            $allowed = array_filter($manifest->hooks, static fn (AllowedHook $allowed): bool => $allowed->allows($hook->command, $hook->phase));

            self::assertCount(1, $allowed, $class);
        }

        self::assertSame(FixtureAddonServiceProvider::NAMESPACE, $manifest->namespace->value);
        self::assertSame(ClassificationAccess::Public, $manifest->capabilities->reads);
        self::assertSame([FixtureArticle::TYPE], array_map(static fn (TypeName $type): string => $type->value, $manifest->schema->extends));
    }

    #[Test]
    public function cms_build_verifies_its_signed_panel_bundle_against_the_key_the_workbench_trusts(): void
    {
        self::assertSame(0, $this->build());

        $addon = array_values(array_filter(
            $this->entries('addons'),
            static fn (array $entry): bool => ($entry['package'] ?? null) === FixtureAddonServiceProvider::PACKAGE,
        ));

        self::assertCount(1, $addon);
        self::assertIsArray($addon[0]['panel']);
        self::assertIsArray($addon[0]['panel']['bundle']);
        self::assertSame('panel.js', $addon[0]['panel']['bundle']['entry']);

        app(Repository::class)->set('cbox-cms.addons.publishers', [FixtureAddonServiceProvider::PACKAGE => ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']]);

        self::assertSame(65, $this->build());
    }

    #[Test]
    public function cms_build_compiles_its_hooks_and_its_extension(): void
    {
        self::assertSame(0, $this->build());

        $hooks = array_values(array_filter(
            $this->entries('hooks'),
            static fn (array $entry): bool => ($entry['package'] ?? null) === FixtureAddonServiceProvider::PACKAGE,
        ));
        $schema = array_values(array_filter(
            $this->entries('schema'),
            static fn (array $entry): bool => ($entry['namespace'] ?? null) === FixtureAddonServiceProvider::NAMESPACE,
        ));

        self::assertSame(
            [[DeriveSlug::class, 'entry.create', 'transform', 'fixtureaddon', 'public'], [RequireSlugOnRelease::class, 'variant.release', 'validate', 'fixtureaddon', 'public']],
            array_map(static fn (array $hook): array => [$hook['class'] ?? null, $hook['command'] ?? null, $hook['phase'] ?? null, $hook['addon'] ?? null, $hook['reads'] ?? null], $hooks),
        );
        self::assertCount(1, $schema);
        self::assertSame([FixtureArticle::TYPE], $schema[0]['extends'] ?? null);
        self::assertSame(FixtureAddonServiceProvider::PACKAGE, $schema[0]['package'] ?? null);
    }

    #[Test]
    public function the_generated_type_catalog_has_its_field_beside_the_owners_field_of_the_same_handle(): void
    {
        $type = app(TypeCatalog::class)->named(new TypeName(FixtureArticle::TYPE));
        self::assertNotNull($type);

        $extension = $type->field(FixtureArticle::namespace(), FixtureArticle::slug());
        $owner = $type->field(null, new FieldHandle(FixtureArticle::SLUG));

        self::assertInstanceOf(FieldDefinition::class, $extension);
        self::assertInstanceOf(FieldDefinition::class, $owner);
        self::assertSame('ext.fixtureaddon.fixture_slug', $extension->address());
        self::assertSame('ext__fixtureaddon__fixture_slug', $extension->column?->name);
        self::assertFalse($extension->required);
        self::assertSame('fixture_slug', $owner->column?->name);
        self::assertSame(ClassificationAccess::Public, $extension->classification);
    }
}
