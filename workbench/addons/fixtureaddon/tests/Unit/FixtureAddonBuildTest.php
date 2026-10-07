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
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\FixtureAddon\Articles\Actions\ListFixtureArticlesAction;
use Workbench\FixtureAddon\DenySelfGrant;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireSlugOnRelease;
use Workbench\FixtureAddon\RequireWellFormedSlug;
use Workbench\FixtureAddon\Slug\Actions\SetArticleSlugAction;
use Workbench\FixtureAddon\Slug\Domain\Commands\SetArticleSlug;
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

        foreach ([DeriveSlug::class, RequireWellFormedSlug::class, RequireSlugOnRelease::class, DenySelfGrant::class] as $class) {
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
            [[DeriveSlug::class, 'entry.create', 'transform', 'fixtureaddon', 'public'], [RequireWellFormedSlug::class, 'entry.create', 'validate', 'fixtureaddon', 'public'], [DenySelfGrant::class, 'grant.assign', 'authorize', 'fixtureaddon', 'public'], [RequireSlugOnRelease::class, 'variant.release', 'validate', 'fixtureaddon', 'public']],
            array_map(static fn (array $hook): array => [$hook['class'] ?? null, $hook['command'] ?? null, $hook['phase'] ?? null, $hook['addon'] ?? null, $hook['reads'] ?? null], $hooks),
        );
        self::assertCount(1, $schema);
        self::assertSame([FixtureArticle::TYPE], $schema[0]['extends'] ?? null);
        self::assertSame(FixtureAddonServiceProvider::PACKAGE, $schema[0]['package'] ?? null);
    }

    /**
     * The addon's own command compiles from its scan root: the command by name and version, and
     * its write action beside the addon's query action, each on REST and Inertia.
     */
    #[Test]
    public function cms_build_compiles_its_own_command_and_its_actions(): void
    {
        self::assertSame(0, $this->build());

        $commands = array_values(array_filter(
            $this->entries('commands'),
            static fn (array $entry): bool => ($entry['package'] ?? null) === FixtureAddonServiceProvider::PACKAGE,
        ));
        $actions = array_values(array_filter(
            $this->entries('actions'),
            static fn (array $entry): bool => ($entry['package'] ?? null) === FixtureAddonServiceProvider::PACKAGE,
        ));

        self::assertSame([[SetArticleSlug::class, 'fixtureaddon.slug.set', 1]], array_map(static fn (array $command): array => [$command['class'] ?? null, $command['name'] ?? null, $command['version'] ?? null], $commands));
        self::assertSame(
            [[ListFixtureArticlesAction::class, 'fixtureaddon.articles', 'query'], [SetArticleSlugAction::class, 'fixtureaddon.slug.set', 'write']],
            array_map(static fn (array $action): array => [$action['class'] ?? null, $action['command'] ?? null, $action['kind'] ?? null], $actions),
        );
    }

    /**
     * cms:panel:fills shows the addon's contributions in the order the browser tests assert
     * (tests/Browser/Panel/PanelAddonsTest.php): the who-am-I page's sections by priority, the
     * faulty one disabled by the workbench's kill switch, the checks of the command form and the
     * shell's entries after the core's own, and the input of its own value class after the
     * core's pickers.
     */
    #[Test]
    public function cms_panel_fills_lists_its_contributions_in_the_order_the_host_renders_them(): void
    {
        self::assertSame(0, $this->build());

        self::assertSame([
            [FixtureAddonServiceProvider::RECENT_ACTIVITY, FixtureAddonServiceProvider::RECENT_ACTIVITY_PRIORITY, true, 'addon', null],
            [FixtureAddonServiceProvider::MY_ARTICLES, FixtureAddonServiceProvider::MY_ARTICLES_PRIORITY, true, 'addon', 'fixtureaddon.articles@1'],
            [FixtureAddonServiceProvider::FAULTY, FixtureAddonServiceProvider::FAULTY_PRIORITY, false, 'activation', null],
        ], $this->fills('account.me.sections@1'));
        self::assertSame([
            [FixtureAddonServiceProvider::SELF_GRANT, 1000, true, 'addon', null],
            [FixtureAddonServiceProvider::SLUG_HINT, 1000, true, 'addon', null],
            [FixtureAddonServiceProvider::SLUG_OVERRIDE, 1000, true, 'addon', null],
            [FixtureAddonServiceProvider::SLUG_SHAPE, 1000, true, 'addon', null],
        ], $this->fills('command.form.checks@1'));
        self::assertSame([
            ['cms.account-me', 100, true, 'addon', null],
            ['cms.roles', 200, true, 'addon', null],
            ['cms.grants', 300, true, 'addon', null],
            [FixtureAddonServiceProvider::ARTICLES_LINK, 1000, true, 'addon', null],
        ], $this->fills('shell.nav@1'));
        self::assertSame([[FixtureAddonServiceProvider::LOGIN_NOTICE, 1000, true, 'addon', null]], $this->fills('login.notice@1'));
        self::assertSame([[FixtureAddonServiceProvider::ACTIVITY, 1000, true, 'addon', null]], $this->fills('panel.observe.command@1'));
        self::assertSame([
            ['cms.actor-picker', 100, true, 'addon', 'actor.list@1'],
            ['cms.node-picker', 100, true, 'addon', 'node.list@1'],
            ['cms.role-picker', 100, true, 'addon', 'role.list@1'],
            [FixtureAddonServiceProvider::SLUG_INPUT, 1000, true, 'addon', null],
        ], $this->fills('command.form.field@1'));
        self::assertSame([[FixtureAddonServiceProvider::ARTICLES, 1000, true, 'addon', 'fixtureaddon.articles@1']], $this->fills('shell.page@1'));
        self::assertSame([[FixtureAddonServiceProvider::NEW_ARTICLE, 1000, true, 'addon', null]], $this->fills('shell.user-menu@1'));
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

    /**
     * The fills of a point as cms:panel:fills lists them, each its id, priority, whether it is
     * enabled, where its enabled state comes from, and its data query.
     *
     * @return list<array{string, int, bool, string, string|null}>
     */
    private function fills(string $point): array
    {
        $kernel = app(Kernel::class);
        self::assertSame(0, $kernel->call('cms:panel:fills', ['point' => $point, '--json' => true]), $point);

        $document = json_decode($kernel->output(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['fills']);

        $fills = [];

        foreach ($document['fills'] as $fill) {
            self::assertIsArray($fill);
            self::assertIsString($fill['contribution']);
            self::assertIsInt($fill['priority']);
            self::assertIsBool($fill['enabled']);
            self::assertIsString($fill['enabling']);
            self::assertTrue($fill['query'] === null || is_string($fill['query']));
            $fills[] = [$fill['contribution'], $fill['priority'], $fill['enabled'], $fill['enabling'], $fill['query']];
        }

        return $fills;
    }
}
