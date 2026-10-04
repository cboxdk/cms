---
title: Write a hook in an addon
weight: 42
description: "Write an authorize, transform or validate hook in an addon: the package and its manifest, the hook class with its budget, a blueprint extension when it sets a field, and its tests with the testkit and on Postgres."
---

# Write a hook in an addon

A hook takes part in a command it does not own: it denies it, derives fields of the revision it writes, or adds a rule of its own (PRD 6.2, 6.3). An addon's hook runs only when the addon's manifest allows it for that command and phase, and it sees only the fields the manifest lets it read (invariant 21). The workbench's fixture addon, `cboxdk/cms-fixture-addon` in `workbench/addons/fixtureaddon`, was built this way (M1-T45). [Hooks](../addons/hooks.md) and [the addon manifest](../addons/manifest.md) describe the extension points; this recipe is the order to build one in.

## Inputs

- **addon**: its Composer package, such as `cboxdk/cms-fixture-addon`, its PHP namespace, such as `Workbench\FixtureAddon`, and its addon namespace, such as `fixtureaddon` (`[a-z][a-z0-9]{0,19}`, never `app` or `ext`). For a hook in an addon that exists, these are given.
- **command**: the `#[Command]` class the hook runs for, such as `CreateEntry`. A hook runs for that command's name and version only.
- **phase**: `Phase::Authorize` (`AuthorizeHook`), `Phase::Transform` (`TransformHook`) or `Phase::Validate` (`ValidateHook`).
- **priority**: within a phase the lowest runs first, then by package, then by class.
- **budget**: `budgetMs`, 1 to 20. All hooks of one command have 100 ms together.
- **reads**: the highest classification the addon's hooks see, `ClassificationAccess::Public` unless the hook needs more.
- **extension field**: when the hook sets or requires a field the addon adds to another owner's type, its handle and the type's `type_id`.

## Files

| Path | What it holds | Written by |
|---|---|---|
| `workbench/addons/<namespace>/composer.json` | the package: `require` of `cboxdk/cms: ^1.0`, PSR-4 of its `src/`, and its provider in `extra.laravel.providers` (new addon only) | hand |
| `composer.json` at the root | a path repository for the package with `"reference": "config"` and a fixed version, the package in `require-dev`, and its tests' namespace in `autoload-dev` (new addon only) | hand |
| `workbench/addons/<namespace>/src/<Addon>ServiceProvider.php` | `DeclaresScanRoots` with the scan root, and `DeclaresAddon` with the manifest: an `AllowedHook(<command>, <phase>)` per hook, `AddonCapabilities(reads: ...)`, and the schema it extends | hand |
| `workbench/addons/<namespace>/src/<Hook>.php` | the hook: `#[Hook(command: ..., phase: ..., priority: ..., budgetMs: ...)]` on a `final readonly` class that implements the phase's interface | hand |
| `workbench/addons/<namespace>/schema/<type handle>.yaml` | a blueprint extension, `kind: extension`, that adds the addon's field to the type (only for an extension field) | hand |
| `workbench/app/Providers/WorkbenchServiceProvider.php` | the addon's schema root in `cbox-cms.generators.roots` under its namespace (only for a new schema root) | hand |
| `workbench/addons/<namespace>/docs/index.md` | the addon's documentation, which the manifest's `docs` names (new addon only) | hand |
| `workbench/addons/<namespace>/tests/Unit/<Hook>Test.php` | the hook called directly with a `PlanView` built by hand | hand |
| `workbench/addons/<namespace>/tests/Unit/<Addon>BuildTest.php` | the manifest allows each hook, and `cms:build` compiles them | hand |
| `tests/Postgres/WalkingSkeleton/<Name>Test.php` | the hook through the real command pipeline on Postgres | hand |
| generated code of the extended type | the extension field in the record, codec, validator, TypeScript and a new `_add_columns` migration | generated |

## Steps

1. For a new addon, write its `composer.json`, add the path repository and `require-dev` entry at the root, and run `composer update <package>`. Composer's lock holds a hash of the addon's `composer.json`, so run it again whenever that file changes.
2. Write the hook class. It is deterministic and does no IO: the testkit's PHPStan rule `cboxCms.hookIo` reports any database, cache, HTTP or file access in a hook class, and cannot be ignored. Work that needs IO belongs in a subscriber.
3. Add an `AllowedHook` for it to the manifest. Without it, `cms:build` fails with `registry_undeclared_hook`; a class that does not implement its phase's interface fails with `registry_not_a_hook`.
4. For an extension field, write the blueprint extension and run `vendor/bin/testbench cms:schema:editor` and `vendor/bin/testbench cms:generate`, as in [Add a content type](content-type.md). An extension field is never required by the kernel, because the owner's code writes entries without knowing it; a validate hook on `variant.release` requires it at the release (invariant 36).
5. Run `vendor/bin/testbench cms:build`, then `vendor/bin/testbench cms:hooks <command name>` to see the hook in the order the pipeline runs it, with its budget.
6. Write the tests, run `composer check`, and record the new tests in `CHECKS-LOG.md`.

## Checks

- The hook's unit tests call `transform()`, `validate()` or `authorize()` on a `PlanView` built by hand, with a test for the fields it changes or the errors it adds, and one for a plan it must leave alone, such as a revision of another type. The hook never sees the plan itself, so what it does is all in its answer.
- The build test boots the addon in its own Testbench application with package discovery on, checks each hook's `#[Hook]` against the manifest, and runs `cms:build` into a temporary bootstrap directory.
- The Postgres test runs the hook in the real pipeline with the compiled registry's hooks: the field it derives is stored and a release its rule refuses is `validation_failed` with `validation_hook_failed` at the field. The kernel's own tests of the budgets, `hook_budget_exceeded`, are in `packages/core/tests/Actions/CommandPipelineHooksTest.php`; a hook's test does not repeat them.
- `composer check` passes. PHPStan covers the addon's code with the testkit's rules, so `cboxCms.hookIo` and `cboxCms.internalUse` hold for it too.

## Running example

The fixture addon's provider, with its scan root and its manifest:

<!-- example-file: workbench/addons/fixtureaddon/src/FixtureAddonServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the workbench's fixture addon, cboxdk/cms-fixture-addon (PRD 13.1, 13.2,
 * MILESTONES M1 point 7). Its scan root holds the addon's two hooks, and its manifest says what the
 * addon does through the kernel: it is named fixtureaddon, needs the core API 1.0, reads public
 * fields only, may transform entry.create and validate variant.release, and
 * extends app:fixture_article with the blueprint in its schema directory. In the panel it ships one
 * theme, brand, a magenta accent (PRD 13.4), which has no effect until the installation selects it
 * in cbox-cms.panel.themes; the workbench does not, and a prebuilt bundle in dist/panel that
 * registers no contribution, built from resources/panel by `npm run build:fixture-addon` and
 * signed with the test key panel-signing-test-key.pem, whose public key the workbench trusts in
 * cbox-cms.addons.publishers (PRD 13.8).
 *
 * It registers nothing at run time: cms:build compiles the hooks from the scan root and the
 * manifest, and cms:generate reads the blueprint from the schema root the application names for the
 * owner fixtureaddon.
 */
final class FixtureAddonServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    /** The addon's Composer package, which its scan root and manifest name. */
    public const string PACKAGE = 'cboxdk/cms-fixture-addon';

    /** The addon's namespace, of its extension fields. */
    public const string NAMESPACE = 'fixtureaddon';

    /** The name of the addon's panel theme, which the installation selects as fixtureaddon:brand. */
    public const string THEME = 'brand';

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: self::PACKAGE,
            namespace: new AddonNamespace(self::NAMESPACE),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__.'/../docs',
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Public, uiTheme: true),
            hooks: [
                new AllowedHook(CreateEntry::class, Phase::Transform),
                new AllowedHook(ReleaseVariant::class, Phase::Validate),
            ],
            schema: new SchemaContributions(
                extends: [new TypeName(FixtureArticle::TYPE)],
                directory: __DIR__.'/../schema',
            ),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                bundle: __DIR__.'/../dist/panel',
                themes: [self::THEME => __DIR__.'/../resources/panel/theme.json'],
            ),
        );
    }
}
```

A transform hook on `entry.create` that derives the addon's slug from the owner's title:

<!-- example-file: workbench/addons/fixtureaddon/src/DeriveSlug.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;

/**
 * The fixture addon's transform hook on entry.create (PRD 6.3, 11.12): derives the addon's field
 * ext.fixtureaddon.fixture_slug of every revision of app:fixture_article the plan writes from the
 * owner's fixture_title, when the revision sets no slug of its own. It is deterministic and does no
 * IO; the kernel validates the slug with the rest of the fields after it. A later entry.revise
 * writes every field again, so it keeps a slug only when the caller sends it.
 *
 * The slug is the title in lower case with every run of other characters than a to z and 0 to 9
 * as one hyphen, without hyphens at its ends, and at most 120 characters, as the blueprint allows.
 * A revision without a title, or one whose title has no letter or digit, gets no slug.
 */
#[Hook(command: CreateEntry::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class DeriveSlug implements TransformHook
{
    /** The longest slug, the blueprint's max_length. */
    public const int MAX_LENGTH = 120;

    public function transform(PlanView $plan): FieldChanges
    {
        $changes = [];

        foreach ($plan->revisions() as $revision) {
            $slug = $this->slugFor($revision);

            if ($slug instanceof TextValue) {
                $changes[] = FieldChange::extension(new VariantRef($revision->entry, $revision->variant), FixtureArticle::namespace(), FixtureArticle::slug(), $slug);
            }
        }

        return new FieldChanges(...$changes);
    }

    /**
     * The slug of a title: null when it has no letter or digit.
     */
    public static function slugOf(string $title): ?string
    {
        $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 0, self::MAX_LENGTH), '-');

        return $slug === '' ? null : $slug;
    }

    private function slugFor(RevisionCreated $revision): ?TextValue
    {
        if (! FixtureArticle::is($revision->type) || $this->isSet($revision->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug()))) {
            return null;
        }

        $title = $revision->fields->own->get(FixtureArticle::title());
        $slug = $title instanceof TextValue ? self::slugOf($title->value) : null;

        return $slug === null ? null : new TextValue($slug);
    }

    private function isSet(?FieldValue $value): bool
    {
        return $value instanceof FieldValue && ! $value instanceof NullValue;
    }
}
```

Its test, on a `PlanView` built by the test helper `PlanViews` (`workbench/addons/fixtureaddon/tests/Unit/PlanViews.php`):

<!-- example: workbench/addons/fixtureaddon/tests/Unit/DeriveSlugTest.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\FixtureArticle;

/**
 * The fixture addon's transform hook on entry.create: it derives ext.fixtureaddon.fixture_slug
 * from the owner's title for every revision of app:fixture_article without a slug, and changes
 * nothing else.
 */
final class DeriveSlugTest extends TestCase
{
    #[Test]
    public function it_derives_the_slug_of_each_article_from_its_title(): void
    {
        $views = new PlanViews;
        $one = $views->entry();
        $two = $views->entry();

        $changes = new DeriveSlug()->transform($views->create(
            $views->revision($one, PlanViews::article(), PlanViews::fields('A quiet week, mostly')),
            $views->revision($two, PlanViews::article(), PlanViews::fields('  Ünïcode & Co. 2026!  ')),
        ))->changes;

        self::assertEquals([
            FieldChange::extension(new VariantRef($one, VariantKey::shared()), FixtureArticle::namespace(), FixtureArticle::slug(), new TextValue('a-quiet-week-mostly')),
            FieldChange::extension(new VariantRef($two, VariantKey::shared()), FixtureArticle::namespace(), FixtureArticle::slug(), new TextValue('n-code-co-2026')),
        ], $changes);
    }

    #[Test]
    public function it_keeps_a_slug_the_revision_sets_and_derives_none_without_a_title(): void
    {
        $views = new PlanViews;

        $changes = new DeriveSlug()->transform($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'chosen')),
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields(null)),
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('!!!')),
        ));

        self::assertTrue($changes->isEmpty());
    }

    #[Test]
    public function it_leaves_the_revisions_of_other_types_alone(): void
    {
        $views = new PlanViews;

        self::assertTrue(new DeriveSlug()->transform($views->create(
            $views->revision($views->entry(), $views->otherType(), PlanViews::fields('A quiet week')),
        ))->isEmpty());
    }

    #[Test]
    public function it_cuts_a_slug_to_the_length_the_blueprint_allows(): void
    {
        $slug = DeriveSlug::slugOf(str_repeat('word ', 30));

        self::assertNotNull($slug);
        self::assertSame(DeriveSlug::MAX_LENGTH - 1, strlen($slug));
        self::assertStringEndsWith('d', $slug);
        self::assertNull(DeriveSlug::slugOf(' - '));
    }
}
```

A validate hook on `variant.release` that requires the slug at the release:

<!-- example-file: workbench/addons/fixtureaddon/src/RequireSlugOnRelease.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;

/**
 * The fixture addon's validate hook on variant.release (PRD 6.3, 11.12, invariant 36): a revision
 * of app:fixture_article is released only with the addon's field ext.fixtureaddon.fixture_slug.
 * The field is optional in the blueprint, because the owner's code creates and revises entries
 * without knowing it, so the addon requires it here, at the release, and never on entry.create or
 * entry.revise. The kernel adds the error as validation_hook_failed at the field and rejects the
 * release with validation_failed.
 */
#[Hook(command: ReleaseVariant::class, phase: Phase::Validate, priority: 10, budgetMs: 2)]
final readonly class RequireSlugOnRelease implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->releases() as $released) {
            $slug = $released->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug());

            if (FixtureArticle::is($released->release->type) && (! $slug instanceof TextValue || $slug->value === '')) {
                $errors[] = HookError::onField(
                    FixtureArticle::slug(),
                    sprintf('Revision %d has no slug; save it with ext.fixtureaddon.fixture_slug before it is released.', $released->release->revision->value),
                    FixtureArticle::namespace(),
                );
            }
        }

        return new HookErrors(...$errors);
    }
}
```

<!-- example: workbench/addons/fixtureaddon/tests/Unit/RequireSlugOnReleaseTest.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Hooks\HookError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireSlugOnRelease;

/**
 * The fixture addon's validate hook on variant.release: a revision of app:fixture_article is
 * released only with ext.fixtureaddon.fixture_slug (invariant 36).
 */
final class RequireSlugOnReleaseTest extends TestCase
{
    #[Test]
    public function it_requires_the_slug_of_a_released_article(): void
    {
        $views = new PlanViews;

        $errors = new RequireSlugOnRelease()->validate($views->release($views->entry(), PlanViews::article(), 3, PlanViews::fields('A quiet week')))->errors;

        self::assertEquals([HookError::onField(
            FixtureArticle::slug(),
            'Revision 3 has no slug; save it with ext.fixtureaddon.fixture_slug before it is released.',
            FixtureArticle::namespace(),
        )], $errors);
    }

    #[Test]
    public function it_releases_an_article_with_a_slug_and_other_types_without_one(): void
    {
        $views = new PlanViews;
        $hook = new RequireSlugOnRelease;

        self::assertTrue($hook->validate($views->release($views->entry(), PlanViews::article(), 1, PlanViews::fields(null, 'a-quiet-week')))->isEmpty());
        self::assertTrue($hook->validate($views->release($views->entry(), $views->otherType(), 1, PlanViews::fields('A quiet week')))->isEmpty());
    }

    #[Test]
    public function it_has_nothing_to_say_about_a_plan_that_releases_nothing(): void
    {
        $views = new PlanViews;

        self::assertTrue(new RequireSlugOnRelease()->validate($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week')),
        ))->isEmpty());
    }
}
```

The build test, on the addon's own test case `FixtureAddonTestCase` (`workbench/addons/fixtureaddon/tests/FixtureAddonTestCase.php`):

<!-- example: workbench/addons/fixtureaddon/tests/Unit/FixtureAddonBuildTest.php -->
```php
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
```

The addon's blueprint extension is `workbench/addons/fixtureaddon/schema/fixture_article.yaml`, and `tests/Postgres/WalkingSkeleton/AddonExtensionTest.php` runs both hooks through the command pipeline on Postgres.
