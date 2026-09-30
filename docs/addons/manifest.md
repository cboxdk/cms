---
title: Addon manifest
weight: 36
description: "Declare an addon: its namespace, the core API it needs, its capabilities, the hooks and subscriptions it may register, its schema contributions and its documentation, and how cms:build and the kernel hold it to them."
---

# Addon manifest

<!-- extension-point: Cbox\Cms\Contracts\Build\DeclaresAddon -->

An addon is a Composer package that declares, in one manifest, everything it does through the kernel (PRD 13.1). Its service provider implements `Cbox\Cms\Contracts\Build\DeclaresAddon` and returns a `Cbox\Cms\Contracts\Addons\AddonManifest` from `addonManifest()`, usually next to `DeclaresScanRoots` for the classes of its hooks and subscribers ([build declarations](build-declarations.md)). `cms:build` asks every registered provider that implements it, deferred providers included, and compiles the manifests with the scan roots. A package without a manifest is not an addon: its hooks and subscribers are the application's or a module's, and no manifest limits them.

## What the manifest holds

| Argument | Value |
|---|---|
| `package` | The addon's Composer package name, such as `acme/cms-reviews`. Its scan roots carry the same name, and every hook and subscriber found in them is held to this manifest. A package has one manifest. |
| `namespace` | An `AddonNamespace`: the addon's name, and the namespace of its extension fields (`ext.<namespace>.<handle>`), its field types and its own types. |
| `coreApi` | A `CoreApiVersion`, the version of the kernel's API the addon needs, read as `^major.minor`. |
| `docs` | The absolute directory of the addon's documentation, built from `__DIR__`. Every addon has one. |
| `capabilities` | An `AddonCapabilities`: what the kernel hands the addon. Public by default. |
| `hooks` | A list of `AllowedHook`: each command class and phase a hook of the addon may run for. |
| `subscriptions` | A list of `AllowedSubscription`: each event class a subscriber of the addon may receive, and the lane. |
| `schema` | A `SchemaContributions`: the field types the addon contributes with the class of their contributor ([Addon field types](field-types.md)), the types it owns, the types of others it extends, and the absolute directory of its blueprint files. |

All of them live in `Cbox\Cms\Contracts\Addons` and are `#[Experimental]`. A manifest that breaks a rule throws `InvalidAddonManifest` from its constructor, and a reserved namespace throws `ReservedAddonNamespace`; `cms:build` turns either into a build error that names the provider.

### The namespace

A namespace is a lowercase letter followed by at most 19 lowercase letters and digits, without an underscore: `acme`, `reviews`, `shop2`. `app` is the application's own namespace and `ext` holds every extender's (PRD 11.12), so neither can be an addon's. A namespace is unique in the installation: two addons with one name stop the build.

The manifest holds its schema contributions to the namespace: every field type is `<namespace>:<handle>`, such as `reviews:stars`, every own type is `<namespace>:<handle>`, and the addon extends no type of its own, because an owner adds fields to its own type in the type's blueprint. A field type, a type or an allowed hook or subscription listed twice is refused, and so are field types without the class of their contributor, `fieldTypeContributor`, or a contributor without field types.

### The core API version

`CoreApiVersion` is a major and a minor version. The manifest names the version it needs, and `CoreApiVersion::current()` is the kernel's, 1.0 today. The kernel satisfies the manifest when it has the same major version and at least the minor version, as the Composer constraint `^1.0` reads. A manifest the kernel does not satisfy stops the build with `registry_incompatible_core_api`, so an addon made for another major version, or a later minor version, never runs against this kernel.

### Capabilities

`AddonCapabilities(reads: ...)` is the highest classification of fields the kernel hands the addon's hooks. The kernel gives every hook of the addon a view of the pending plan with the fields up to the lower of this and the actor's classification access, and refuses a transform hook's change of a field above it with `hook_change_refused` ([hooks](hooks.md)). An addon that reads up to internal never sees a confidential field, whoever runs the command, and one that reads up to sensitive never sees more than the actor may read. A hook of a package without a manifest sees what the actor may read (invariant 21).

### Hooks and subscriptions

The manifest allows hooks; it does not repeat them. Each hook class still declares its command, phase, priority and budget with `#[Hook]`, and `cms:build` refuses a `#[Hook]` of the addon's package whose command class and phase no `AllowedHook` names, with `registry_undeclared_hook`. In the same way it refuses a `#[Subscription]` of the addon's package that receives an event class on a lane no `AllowedSubscription` names, with `registry_undeclared_subscriber`. A hook entry in `hooks.php` names the addon and what it reads, and a subscriber entry in `subscribers.php` names the addon.

An addon's subscribers run as the addon's own service actor, with that actor's grants, never as the system. The actor is created when the installation approves the addon's capabilities, so its id belongs to the installation: set it in `cbox-cms.addons.service_actors` under the addon's namespace ([configuration](../developers/configuration.md)). A subscriber of an addon without an active service actor does not run, with `addon_service_actor_unavailable`.

### Schema contributions and schema.php

`cms:build` writes each addon's schema contributions to `bootstrap/cache/cms/schema.php`, one entry per addon sorted by namespace, with the lists sorted by name:

| Key | Value |
|---|---|
| `extends` | The types of others the addon extends, as `<owner>:<handle>`. |
| `field_type_contributor` | The class of the addon's `FieldTypeContributor`, which `cms:generate` makes to read its field types, or null when it contributes none. |
| `field_types` | The field types the addon contributes, as `<namespace>:<handle>`. |
| `namespace` | The addon's namespace. |
| `package` | The addon's Composer package. |
| `types` | The types the addon owns, as `<namespace>:<handle>`. |

Like the other registry files, it holds no time and no path, so two builds of the same code write the same bytes. The blueprint files themselves stay in the addon's schema directory; `cms:build` checks that the directory, and the documentation directory, are readable directories.

## Build errors

A build with any of these writes nothing and exits 65, listing every problem ([error codes](../reference/errors.md)):

| Code | When |
|---|---|
| `registry_invalid_manifest` | A manifest cannot be built, its documentation or schema directory is not a readable directory, its field type contributor is not a class that implements `FieldTypeContributor`, or two manifests name one package. |
| `registry_reserved_namespace` | A manifest names `app` or `ext`. |
| `registry_duplicate_namespace` | Two manifests name one namespace. |
| `registry_incompatible_core_api` | The kernel does not satisfy the manifest's core API version. |
| `registry_undeclared_hook` | A hook of the addon's package runs for a command and phase its manifest does not allow. |
| `registry_undeclared_subscriber` | A subscriber of the addon's package receives an event on a lane its manifest does not allow. |

## Example

The addon `acme/cms-reviews` validates the notes package's `note.publish` from [build declarations](build-declarations.md) and contributes the field type `reviews:stars`, whose contributor is on [Addon field types](field-types.md). Its provider declares the scan root and the manifest:

<!-- example-file: examples/Unit/Addons/Reviews/ReviewsServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Examples\Unit\Build\Notes\PublishNote;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the addon acme/cms-reviews. Its scan root holds the addon's hook, and
 * its manifest says what the addon does: it is named reviews, needs the core API 1.0, reads fields
 * up to internal, may validate the notes package's note.publish, and contributes the field type
 * reviews:stars, which ReviewsFieldTypes gives cms:generate.
 */
final class ReviewsServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-reviews', __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: 'acme/cms-reviews',
            namespace: new AddonNamespace('reviews'),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__.'/docs',
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Internal),
            hooks: [new AllowedHook(PublishNote::class, Phase::Validate)],
            schema: new SchemaContributions(
                fieldTypes: [new ContributedFieldType('reviews:stars')],
                fieldTypeContributor: ReviewsFieldTypes::class,
            ),
        );
    }
}
```

Its hook declares itself as any hook does; the manifest allows it:

<!-- example-file: examples/Unit/Addons/Reviews/RequireStars.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Examples\Unit\Build\Notes\PublishNote;

/**
 * The reviews addon's validate hook on the notes package's command. The manifest allows it; the
 * kernel gives it the plan with the fields up to internal, whatever the actor may read.
 */
#[Hook(command: PublishNote::class, phase: Phase::Validate, priority: 30, budgetMs: 2)]
final readonly class RequireStars implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
```

The same addon with a manifest that allows no hook:

<!-- example-file: examples/Unit/Addons/Reviews/UnlistedHookServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The same addon with a manifest that allows no hook, so cms:build refuses the hook in its scan
 * root.
 */
final class UnlistedHookServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-reviews', __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), new CoreApiVersion(1, 0), __DIR__.'/docs');
    }
}
```

The test runs `cms:build` with the notes package and each provider, on the test case of [build declarations](build-declarations.md), and reads `hooks.php` and `schema.php`:

<!-- example: examples/Unit/Addons/AddonManifestTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons;

use Examples\Unit\Addons\Reviews\RequireStars;
use Examples\Unit\Addons\Reviews\ReviewsFieldTypes;
use Examples\Unit\Addons\Reviews\ReviewsServiceProvider;
use Examples\Unit\Addons\Reviews\UnlistedHookServiceProvider;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Build\Notes\NotesServiceProvider;
use Examples\Unit\Build\Notes\PublishNote;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles the reviews addon's manifest with its scan root: the hook the manifest allows
 * is registered under the addon with what it may read, and the field type goes to schema.php. A
 * hook the manifest does not allow stops the build.
 */
final class AddonManifestTest extends BuildTestCase
{
    #[Test]
    public function it_registers_the_addon_s_hook_and_schema_contributions(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        $hooks = require $this->registryFile('hooks');
        self::assertIsArray($hooks);
        self::assertIsArray($hooks['entries']);
        self::assertContains([
            'addon' => 'reviews',
            'budget_ms' => 2,
            'class' => RequireStars::class,
            'command' => 'note.publish',
            'command_class' => PublishNote::class,
            'command_version' => 1,
            'package' => 'acme/cms-reviews',
            'phase' => 'validate',
            'priority' => 30,
            'reads' => 'internal',
        ], $hooks['entries']);

        $schema = require $this->registryFile('schema');
        self::assertIsArray($schema);
        self::assertSame('schema', $schema['registry']);
        self::assertIsArray($schema['entries']);
        self::assertContains([
            'extends' => [],
            'field_type_contributor' => ReviewsFieldTypes::class,
            'field_types' => ['reviews:stars'],
            'namespace' => 'reviews',
            'package' => 'acme/cms-reviews',
            'types' => [],
        ], $schema['entries']);
    }

    #[Test]
    public function it_refuses_a_hook_the_manifest_does_not_allow_and_writes_nothing(): void
    {
        self::assertSame(65, $this->build(NotesServiceProvider::class, UnlistedHookServiceProvider::class));
        self::assertStringContainsString(
            '[registry_undeclared_hook] Hook '.RequireStars::class.' (acme/cms-reviews) runs for '.PublishNote::class.' (note.publish) in the validate phase, which the manifest of addon "reviews" does not allow.',
            $this->buildOutput(),
        );
        self::assertDirectoryDoesNotExist($this->registryDirectory());
    }
}
```
