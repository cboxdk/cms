---
title: Panel contributions
weight: 51
description: "What an addon adds to the panel in its manifest: the kinds of contribution, the checks cms:build runs on them, the bundle manifest, how the installation orders, chooses, disables and allows them, and what a page sends each viewer."
---

# Panel contributions

<!-- extension-point: Cbox\Cms\Contracts\PanelPoints\PanelContribution -->
<!-- extension-point: packages/core/resources/schemas/panel-bundle.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/contributions.v1.json -->

An addon adds to the panel through the `panel` member of its [manifest](manifest.md): a `Cbox\Cms\Contracts\PanelPoints\PanelContributions` that lists every contribution it makes to the [panel points](panel-points.md) (PRD 13.4). The list is the allowance: what it names is exactly what the addon may touch, and the install screen shows it. `cms:build` checks every contribution against the points the scan roots declare, the addon's manifest, the commands, queries and hooks the build registered and their JSON Schemas, and writes the result to `panel.php` and `addons.php` in `bootstrap/cache/cms/`. Every type on this page is `#[Experimental]`.

## The panel member

| Argument | Value |
|---|---|
| `sdk` | A `PanelApiVersion`: the version of the panel's API the addon's UI needs, read as `^major.minor`. `PanelApiVersion::current()` is 1.0. |
| `bundle` | The absolute directory of the addon's prebuilt bundle, which holds `panel-manifest.json`, or null when no contribution runs code. |
| `acceptsExperimental` | The ids of the experimental points the addon contributes to, such as `account.me.sections@1`, each once. An experimental point may change in a minor release of the panel API, so the addon opts in to each. |
| `contributions` | The contributions, each a `PanelContribution`. |
| `themes` | The addon's themes of token values by a local name, each the absolute path of its JSON file, such as `['brand' => __DIR__.'/../resources/panel/theme.json']`. The installation selects one as `<namespace>:<name>`; a theme nothing selects has no effect. See [Branding and theming the panel](../developers/panel-branding.md#themes). |

The capabilities that go with it are in `AddonCapabilities`: `issues`, the command classes the addon's UI may run, and `uiTheme`, whether it may ship a theme ([manifest](manifest.md#capabilities)).

## The kinds of contribution

Every contribution has an id `<namespace>.<local>` in the addon's namespace, the id of the point it contributes to, a priority (1000 by default, after the core's own at 100, 200 and so on, the lowest rendered first) and a `Scope` that narrows it to pages, command forms, types, field types and a permission the viewer must hold. A contribution that runs code is registered in the addon's bundle under its id; the others are data the panel renders.

| Class | Point kind | Runs code | What else it holds |
|---|---|---|---|
| `SlotFill` | slot | yes | `data`: a `#[Query]` of the addon whose result the component gets, with the query's input taken from the point's props by name. |
| `ActionContribution` | action | no | `command`, a command class of the addon's `issues`; `label` and `icon`; `prefill`, command properties filled from JSON pointers into the point's props; `confirm` (`Confirm::None`, `Confirm`, `DryRun`, `Form`); `tone`. |
| `NavContribution` | nav | no | `label`, `icon`, and `page`, the id of a `PageContribution` of the addon. |
| `PageContribution` | page | yes | `path` below `x/<namespace>/`, and `data`, a query of the addon without required input. |
| `DecoratorContribution` | decorator | yes | `tightens`, the props of the point it tightens, and `mirrors`, the hook that enforces a disabled reason on the server. |
| `ReplacementContribution` | replacement | yes | `key`, the field type, command (`<name>@<version>`) or class it replaces. |
| `FormCheck` | form check | yes | `command`, the form's `<name>@<version>`; `severity` (`Info`, `Warning`, `Acknowledge`, `Error`); `mirrors`. |
| `FlowStep` | flow step | yes | `command`; `position` (`BeforeSubmit`, `AfterReceipt`); `patches`, the paths it may change; `timeoutSeconds`, 1 to 30. |
| `ObserverContribution` | observer | yes | nothing more. |
| `ProviderContribution` | provider | yes | nothing more. |
| `LoginNotice` | data | no | `message`, a translation key, and `tone`. |

A value that breaks its rule, such as a priority outside 0 to 1000000, a label that is no translation key, a prefill from a text that is no JSON pointer, a page path that climbs or a step over 30 seconds, throws `InvalidAddonManifest`, and `cms:build` reports it as `registry_invalid_manifest`.

## What cms:build checks

A build with any of these writes nothing and exits 65, listing every problem ([error codes](../reference/errors.md)):

| Code | When |
|---|---|
| `registry_incompatible_panel_api` | The panel does not satisfy the addon's `sdk`. |
| `registry_panel_unknown_point` | A contribution or `acceptsExperimental` names a point no `#[PanelPoint]` declares, or text that is no point id. |
| `registry_panel_internal_point` | It names an `#[Internal]` point. |
| `registry_panel_kind_mismatch` | A contribution is of another kind than its point. |
| `registry_panel_experimental_not_accepted` | A contribution goes to an experimental point `acceptsExperimental` does not list. |
| `registry_panel_duplicate_contribution` | An id is in another namespace or given twice in the installation, two pages of one addon have one path, or an addon in the core's namespace `cms` contributes. |
| `registry_panel_unknown_command` | A check or step is for a command form, or a scope names a command or permission, that no scan root registers. |
| `registry_panel_command_not_issuable` | An action's command is not in `issues`, or a command in `issues` is no registered command exposed on Inertia. |
| `registry_panel_action_prefill_invalid` | A prefill pointer is not in the point's props schema, the property is not in the command's schema, or the types do not fit. |
| `registry_panel_data_query_invalid` | A data query is no `#[Query]` of the addon with a codec, or the point's props cannot give its required input by name. |
| `registry_panel_nav_target_unknown` | A nav entry's page is no page of the addon. |
| `registry_panel_tightening_undeclared` | A decorator tightens a prop its point does not declare. |
| `registry_panel_check_unmirrored` | A check with severity error, or a decorator that tightens the disabled reason, mirrors no `ValidateHook` or `AuthorizeHook` of the addon on the same command (the mirror rule), or the decorator is not scoped to that one command. |
| `registry_panel_flow_path_unknown` | A step patches a path its command's schema does not have, or one outside `ext.<namespace>` of its addon on a command the addon does not declare. |
| `registry_panel_unowned_target` | A replacement at an `Ownership::Own` point replaces a field type the manifest does not contribute, or a command or class no scan root of the addon's package declares. |
| `registry_panel_replacement_conflict` | Two replacements claim one key, and `cbox-cms.panel.replacements` names no winner. |
| `registry_panel_bundle_invalid` | The bundle does not match: see below. |
| `registry_panel_override_invalid` | `cbox-cms.panel.contributions` or `cbox-cms.panel.replacements` is malformed or names what the build does not have. |
| `registry_panel_theme_invalid` | The addon ships a theme without `uiTheme`, or a selected theme of it cannot be read or is not of the theme's form. |
| `registry_panel_theme_contrast` | The selected themes, composed, draw a contrast pair of the token catalogue below WCAG 2.2 AA. |

The JSON Schemas the checks read are each command's and query's, from their generated codecs, and the props schema of each point, `<name>.v<version>.json` in the directories the modules that declare points register as a `PointSchemaDirectory` under `PointSchemaDirectory::TAG`; the panel registers `packages/panel/resources/schemas/points`.

The build still writes the registry, and prints a warning with its code, for every contribution to an experimental point (`registry_panel_point_experimental`), to a deprecated one (`registry_panel_point_deprecated`, with the release it goes in and its replacement), and for each token more than one selected theme sets (`registry_panel_theme_overlap`).

## The bundle

The addon's prebuilt bundle holds `panel-manifest.json`, a document of `panel-bundle.v1.json`: the entry module, every file with its path, its SHA-384 as `sha384-<base64>` and its kind (`script`, `style` or `asset`), the bare module specifiers it imports, and the ids of the contributions it registers code for. `cms:build` reads it through the generated codec `PanelBundleCodecV1` and refuses the bundle with `registry_panel_bundle_invalid` when a file is missing or has another hash, a stylesheet has a rule outside `@layer cms.addon` (or a layer below it), the entry is not one of its scripts, it imports a module other than the shared React modules and `@cboxdk/cms-panel` with its subpaths, or its contributions are not exactly the manifest's contributions that run code. `addons.php` keeps the entry and the files with their hashes, so the panel serves only those files, by hash, and no path on disk.

<!-- example-file: examples/Unit/Panel/Approvals/dist/panel-manifest.json -->
```json
{
  "contributions": ["approvals.badge"],
  "entry": "addon.js",
  "externals": ["@cboxdk/cms-panel/extend", "react", "react/jsx-runtime"],
  "files": [
    {
      "integrity": "sha384-iS4H/x5SnTQ8Vj3DsRVxwIOx/FLS2WnfkAjIXyy/s8iWaeQIueCXzXIRcWYT2yBa",
      "kind": "style",
      "path": "addon.css"
    },
    {
      "integrity": "sha384-B3wbEOZJfswkjwIl/Lup6+5/CVhiMn08tNVNvlmWs2vc/ZxoneWDO/GbDiMMBxu4",
      "kind": "script",
      "path": "addon.js"
    },
    {
      "integrity": "sha384-E9LkkwZSgxYZalyCshUAFWrOhzHOTN+RszI+I7YX4ZkWDPIjKneo4zBWm7TVr1HJ",
      "kind": "script",
      "path": "badge.js"
    }
  ]
}
```

<!-- example: examples/Unit/Panel/PanelBundleManifestTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleCodecV1;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approvals addon's dist/panel-manifest.json is a document of panel-bundle.v1.json: its
 * generated codec reads it, and every file it lists has the SHA-384 it says.
 */
final class PanelBundleManifestTest extends TestCase
{
    #[Test]
    public function it_reads_the_bundle_manifest_and_every_file_has_its_hash(): void
    {
        $directory = __DIR__.'/Approvals/dist';
        $json = file_get_contents($directory.'/panel-manifest.json');
        self::assertIsString($json);

        $manifest = new PanelBundleCodecV1()->decode($json, ClassificationAccess::Public);

        self::assertSame('addon.js', $manifest->entry->value);
        self::assertSame(['approvals.badge'], array_map(static fn (ContributionId $id): string => $id->value, $manifest->contributions));

        foreach ($manifest->files as $file) {
            $bytes = file_get_contents($directory.'/'.$file->path->value);
            self::assertIsString($bytes);
            self::assertTrue(BundleIntegrity::of($bytes)->equals($file->integrity), $file->path->value);
        }

        self::assertSame([BundleFileKind::Style, BundleFileKind::Script, BundleFileKind::Script], array_map(static fn (BundleFile $file): BundleFileKind => $file->kind, $manifest->files));
    }
}
```

## Order, choices and the kill switch

The panel renders a point's contributions by priority, the lowest first, then by the addon's namespace, then by id. The installation changes that without touching an addon, and `cbox-cms.panel.*` in the [configuration](../developers/configuration.md) holds it:

- `cbox-cms.panel.contributions`, per point id and contribution id, sets another `priority` or `enabled => false`. `cms:build` compiles it.
- `cbox-cms.panel.replacements`, per replaceable point id and key, names the contribution id of the replacement that wins when several claim the key. `cms:build` compiles it, and the others stay listed, passed over.
- `cbox-cms.panel.disabled` is the activation state (PRD 13.5): the namespaces of addons whose panel UI is off under `addons`, and contribution ids under `contributions`. The panel reads it at each request, so an incident is handled without a rebuild.

`cms:panel:fills <point>` shows each contribution with where its priority comes from (the addon or the installation) and whether it is enabled and why (the addon, the installation, a replacement the installation chose or passed over, or the activation state), as text and with `--json` ([inspecting](../developers/inspecting.md)).

## The core's own contributions

The panel's pages contribute to their own points too, in the namespace `cms`: the profile section of the who-am-I page, the core's pickers of a command form's fields and the like. A module of `cboxdk/cms` declares them through its service provider's `DeclaresCoreContributions` (`#[Internal]`; the panel module's list is `Cbox\Cms\Panel\Contributions\Domain\CoreContributions`), and `cms:build` compiles them with the addons' onto `panel.php`, at the core's priorities, 100, 200 and so on, so they come before an addon's at its default of 1000, and the installation reorders or disables them as it does an addon's. They are held to the same rules as an addon's, less those that limit an addon to what it owns: a core contribution may sit at an `#[Internal]` or an experimental point without opting in, run any command exposed on Inertia, replace any key and patch any path, and mirrors no hook, because the kernel enforces its own rules on the server. They have no bundle: the panel's own JavaScript registers those that run code with `definePanelAddon()` in `js/panel/src/host/core.ts`, and the host holds that registration to `cms:build`'s list as it holds an addon's bundle to its manifest. They are handed the viewer's classification access. A provider outside `Cbox\Cms` that implements the interface fails the build with `registry_invalid_manifest`, and an addon in the namespace `cms` with `registry_panel_duplicate_contribution`.

## What a page sends a viewer

The server works out, per request and page, which contributions a viewer gets (`Cbox\Cms\Panel\Contributions\Actions\ResolveContributions`):

1. The points the page renders. A page names each point it renders with its props, an object of the newest version's props class; a contribution to an older version of the point gets what that version's downcast builds from them ([panel points](panel-points.md)).
2. The contributions in scope: each compiled fill of those points that the installation's settings and the activation state of now leave enabled, whose `Scope` names the page, if it names pages, and what the page is about, if it names commands, types or field types.
3. `requires`: the viewer must hold the permission of the command or read the scope names, decided by the viewer's grants as the kernel decides any read (`PermissionRule`), asked once per page for every name. A contribution the viewer may not see is never sent, not even its id.
4. Access: each contribution is handed the point's props, and runs its data, at the lower of the viewer's classification access and the addon's `reads` capability. The point's generated codec writes the props at that access, so a member classified above what the addon reads is absent from what it gets, whatever the viewer may read.

The page sends the result as the prop `cms.contributions`, a document of `contributions.v1.json`, which the panel's [host](../developers/panel.md#the-host-runtime) renders every point from:

- per point, its kind, its region when it is a slot, how many contributions it shows (`many`, at most `max`, or `exclusive`), and the fills in render order, each with its id, its addon, its kind, the priority it renders at, the props, whether it reads data, and what its kind needs besides: an action's command, label, icon, prefill, confirmation and tone, a check's form command and severity, a step's form command, position, paths and timeout, what a decorator may tighten, or the key a replacement replaces;
- per addon whose contributions that run code are on the page, the core's namespace `cms` included, the registration its code must match: the SHA-256 of the ids of every contribution of the addon that runs code, as `cms:build` compiled them, sorted and joined by line feeds, so the digest names none the viewer does not get; and the commands its contributions may issue through the host, any for the core's own;
- whether the viewer sees the detail of a contribution that failed, which a viewer whose classification access is internal or above does;
- the panel's pages a contribution may navigate to, by page id, and the address of the Inertia profile the host runs commands through.

A slot fill or a page with a `data` query gets its data as a deferred prop: the host asks for `ext.<namespace>` once the page has rendered, one deferred prop per addon in the group of its namespace, and gets the result of each of the addon's queries under the contribution's id. The query's input is taken from the props by name and read by the query's codec. It runs through the query pipeline as the viewer, from the viewer's own credential, never as the addon, so the viewer's grants, row level security and the actor's query budget (`cbox-cms.queries.budgets.actor`) all hold, with the read capped at the contribution's access, and the query's result codec writes the answer at that access. A query the pipeline rejects, such as one over the budget or one whose permission the viewer does not hold, one that throws, or one whose input the props do not give, leaves the contribution's data absent, so the contribution renders its error state, and the page still answers 200. A failure is reported to the application's exception handler.

Nothing one addon does blanks a page. When the registry cache or the activation state cannot be read, the page has no contribution at all; a point without a codec for its props loses its contributions. The panel records each case, and each data query, per addon through the [telemetry contract](contracts/telemetry.md#what-the-panel-exports).

This example checks `cms.contributions` documents against the schema. It is in the `Codecs` suite:

<!-- example: examples/Codecs/Panel/ContributionsPropTest.php -->
```php
<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// Every panel page behind the login sends the contributions active for the viewer as the prop
// cms.contributions, a document of packages/panel/resources/schemas/pages/contributions.v1.json:
// per point the page renders, its kind and multiplicity and the fills in render order, each with
// the point's props as the point's codec wrote them for it, whether its data comes as the deferred
// prop ext.<addon> and what its kind needs besides; the registration of each addon whose code runs
// on the page; and what the host needs to navigate and run commands.

/**
 * The errors of a cms.contributions document, none when it is valid.
 *
 * @return array<array-key, mixed>
 */
function contributionsErrors(string $document): array
{
    $json = file_get_contents(dirname(__DIR__, 3).'/packages/panel/resources/schemas/pages/contributions.v1.json')
        ?: throw new RuntimeException('Cannot read contributions.v1.json.');
    $error = new CompliantValidator()->validate(json_decode($document), $json)->error();

    return $error instanceof ValidationError ? new ErrorFormatter()->format($error) : [];
}

it('accepts the contributions of a page', function (string $document): void {
    expect(contributionsErrors($document))->toBe([]);
})->with([
    'a page with no active contribution' => ['{"addons":[],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[]}'],
    'a section that reads its data' => ['{"addons":[{"addon":"approvals","any_command":false,"issues":["approvals.request@1"],"registration":"0000000000000000000000000000000000000000000000000000000000000000"}],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[{"fills":[{"action":null,"addon":"approvals","check":null,"data":true,"decorator":null,"id":"approvals.badge","kind":"slot","priority":1000,"props":{"note":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"},"replacement":null,"step":null}],"kind":"slot","max":null,"multiplicity":"many","point":"reviews.detail.sections@1","region":"sections"}]}'],
]);

it('refuses a fill that does not say whether it reads data', function (): void {
    expect(contributionsErrors('{"addons":[{"addon":"approvals","any_command":false,"issues":["approvals.request@1"],"registration":"0000000000000000000000000000000000000000000000000000000000000000"}],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[{"fills":[{"action":null,"addon":"approvals","check":null,"decorator":null,"id":"approvals.badge","kind":"slot","priority":1000,"props":{"note":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"},"replacement":null,"step":null}],"kind":"slot","max":null,"multiplicity":"many","point":"reviews.detail.sections@1","region":"sections"}]}'))
        ->not->toBe([]);
});
```

## The install screen

`Cbox\Cms\Core\Registry\Actions\DiscloseAddons` gives what the install screen shows of each installed addon from the compiled registry: its namespace, package and core API version, its capabilities (`reads`, the commands it issues, `uiTheme`), its panel API version, the experimental points it accepts and its bundle, the points its contributions touch with the experimental ones among them, the number of its hooks and subscribers, and, for an addon with UI, the statement that its UI runs in the panel's window with the viewer's session, while the server still decides every command and query by the viewer's grants.

## Example

The addon `acme/cms-approvals` contributes a section to the experimental slot `reviews.detail.sections@1` of the review package on [panel points](panel-points.md), with its component in the bundle in `dist`:

<!-- example-file: examples/Unit/Panel/Approvals/ApprovalsServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Approvals;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the addon acme/cms-approvals. Its manifest's panel member contributes a
 * section to the review page's slot reviews.detail.sections@1, which is experimental, so the
 * addon accepts it; the section's component is in the prebuilt bundle in dist.
 */
final class ApprovalsServiceProvider extends ServiceProvider implements DeclaresAddon
{
    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: 'acme/cms-approvals',
            namespace: new AddonNamespace('approvals'),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__,
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Internal),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                bundle: __DIR__.'/dist',
                acceptsExperimental: ['reviews.detail.sections@1'],
                contributions: [
                    new SlotFill(new ContributionId('approvals.badge'), 'reviews.detail.sections@1'),
                ],
            ),
        );
    }
}
```

<!-- example: examples/Unit/Panel/PanelContributionsTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Approvals\ApprovalsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles the approvals addon's panel contributions onto the review package's points:
 * the section goes to panel.php in render order, the addon and its checked bundle to addons.php,
 * and the build warns that the point is experimental. The installation can reorder the section
 * without touching the addon, and refuses an addon its allowlist does not name.
 */
final class PanelContributionsTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_addon_s_contribution_and_bundle(): void
    {
        $this->allowAddons('acme/cms-approvals');

        self::assertSame(0, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertStringContainsString('[registry_panel_point_experimental] The contribution approvals.badge of addon "approvals" (acme/cms-approvals) contributes to reviews.detail.sections@1', $this->buildOutput());

        $panel = require $this->registryFile('panel');
        self::assertIsArray($panel);
        self::assertIsArray($panel['entries']);
        $fills = array_column($panel['entries'], 'fills', 'id')['reviews.detail.sections@1'] ?? null;
        self::assertIsArray($fills);
        self::assertSame(['approvals.badge'], array_column($fills, 'contribution'));

        $addons = require $this->registryFile('addons');
        self::assertIsArray($addons);
        self::assertIsArray($addons['entries']);
        $approvals = array_column($addons['entries'], null, 'namespace')['approvals'] ?? null;
        self::assertIsArray($approvals);
        self::assertSame('internal', $approvals['reads']);
        self::assertIsArray($approvals['panel']);
        self::assertSame(['reviews.detail.sections@1'], $approvals['panel']['accepts_experimental']);
        self::assertIsArray($approvals['panel']['bundle']);
        self::assertSame('addon.js', $approvals['panel']['bundle']['entry']);
    }

    #[Test]
    public function it_lets_the_installation_reorder_a_contribution_and_shows_where_the_order_comes_from(): void
    {
        $this->allowAddons('acme/cms-approvals');
        config()->set('cbox-cms.panel.contributions', ['reviews.detail.sections@1' => ['approvals.badge' => ['priority' => 10]]]);

        self::assertSame(0, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@1']));
        self::assertStringContainsString('priority 10 from the installation, enabled', app(Kernel::class)->output());
    }

    #[Test]
    public function it_refuses_an_addon_the_allowlist_does_not_name_and_writes_nothing(): void
    {
        self::assertSame(65, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertStringContainsString('[registry_addon_not_allowed] Addon "approvals" (acme/cms-approvals) is installed', $this->buildOutput());
        self::assertDirectoryDoesNotExist($this->registryDirectory());
    }
}
```
