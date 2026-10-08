---
title: Add a panel action without code
weight: 46
description: "Add a button to the panel that runs a command of the addon as the viewer, from the manifest alone: the action, its command in issues, its prefill and confirmation, and the tests that hold it."
---

# Add a panel action without code

An action is a button the panel renders from the addon's manifest. It runs one command as the viewer, so the addon writes no UI code for it ([action](../addons/panel/kinds/action.md)). The fixture addon's button of the viewer's menu, `fixtureaddon.new-article`, which opens the form of `entry.create`, was added this way.

## Inputs

- **namespace** and **package**: the addon's namespace, such as `fixtureaddon`, and its Composer package.
- **id**: the contribution's id, `<namespace>.<local>`, such as `fixtureaddon.new-article`.
- **point**: an action point, `<name>@<version>`, such as `shell.user-menu@1`.
- **command**: the command the button runs, `<name>@<version>`, a command exposed on Inertia, such as `entry.create@1`.
- **label** and **icon**: the translation key of the button's text in the addon's namespace and a Lucide icon name.
- **prefill**, **confirm** and **tone**: the members of the command's document filled from the point's props by JSON pointer, whether the press runs at once (`None`), asks (`Confirm`), shows a dry run (`DryRun`) or opens the command's form (`Form`), and the button's tone.

## Files

| Path, in the addon's package | What it holds |
|---|---|
| `src/<Addon>ServiceProvider.php` | the manifest: the command's class in `AddonCapabilities::$issues`, the point in `PanelContributions::$acceptsExperimental` while it is experimental, and the `ActionContribution` in `$contributions` |
| `tests/Panel/PanelContributionsTest.php` | the testkit's `PanelContributionsContract` on the manifest, which `cms:make:addon-ui` writes |
| a Pest Browser test | `PanelVisit::as($actor, $path)->assertFill('<id>')`, for an addon that runs browser tests |

## Steps

1. Run `vendor/bin/testbench cms:make:panel action <namespace> <id> --point=<point> --command=<command>`: an action runs no code, so it prints the manifest line alone.
2. Add the line to the manifest's contributions, with the label, icon, prefill, confirm and tone; add the command's class to `issues` and the point to `acceptsExperimental`.
3. Run `vendor/bin/testbench cms:build` and `vendor/bin/testbench cms:panel:fills <point>`, which lists the action in render order with its command.
4. Run the addon's tests.

## Checks

- `cms:build` refuses a command outside `issues` or not exposed on Inertia (`registry_panel_command_not_issuable`), a prefill pointer or member that is not in the schemas (`registry_panel_action_prefill_invalid`), an experimental point the manifest does not accept (`registry_panel_experimental_not_accepted`) and a contribution of another kind (`registry_panel_kind_mismatch`); `PanelContributionsContract` fails with the build's output.
- The panel shows the button only to a viewer who may run the command. `tests/Browser/Panel/PanelAddonsTest.php` shows the fixture addon's action in the viewer's menu in Chromium.

## Running example

The fixture addon's action, compiled with its command:

<!-- example: examples/Unit/Panel/Points/ShellUserMenuTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Points;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Panel\Boundary\Generated\Points\ViewerSummaryCodecV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;
use Examples\Unit\Build\BuildTestCase;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * shell.user-menu@1 in the workbench: cms:build compiles the fixture addon's ActionContribution
 * fixtureaddon.new-article, a button of the viewer's menu that opens the form of entry.create, a
 * command in the addon's issues. An action runs no code: the host renders the button from its
 * data, shows it only to a viewer who may run the command, and hands it the viewer's actor id and
 * issuer as the point's props, for a prefill by JSON pointer.
 */
final class ShellUserMenuTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_addon_s_action_with_its_command(): void
    {
        self::assertSame(0, $this->build());
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'shell.user-menu@1', '--json' => true]));

        $document = json_decode(app(Kernel::class)->output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['fills']);
        self::assertSame(['fixtureaddon.new-article'], array_column($document['fills'], 'contribution'));
        self::assertSame(['action'], array_column($document['fills'], 'kind'));
        self::assertSame(['entry.create@1'], array_column($document['fills'], 'command'));

        $viewer = new ViewerSummaryV1(ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'), IssuerKind::Human);
        self::assertSame('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","issuer":"human"}', new ViewerSummaryCodecV1()->encode($viewer, ClassificationAccess::Public));
    }
}
```

The fixture addon's manifest held to the build by the shared suite:

<!-- example: workbench/addons/fixtureaddon/tests/Unit/FixtureAddonPanelContributionsTest.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Testkit\Panel\PanelContributionsContract;
use Override;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\Tests\FixtureAddonTestCase;

/**
 * The fixture addon's panel contributions run the testkit's shared suite (PRD 13.4): cms:build
 * compiles the installation with the addon's manifest and refuses nothing, as every addon that
 * contributes to the panel checks in its own CI.
 */
final class FixtureAddonPanelContributionsTest extends FixtureAddonTestCase
{
    use PanelContributionsContract;

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return new FixtureAddonServiceProvider(app())->addonManifest();
    }
}
```
