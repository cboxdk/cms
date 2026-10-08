---
title: "shell.user-menu@1"
weight: 43
description: "The actions of the viewer's menu in the panel's shell: a button that runs a command of the addon's issues, prefilled from the viewer."
---

# shell.user-menu@1

<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.user-menu.v1.json -->

The viewer's menu of the [shell](../shell.md#actions). An action is a button that runs one command as the viewer.

| | |
|---|---|
| Kind | [action](../kinds/action.md) |
| Page | `shell` |
| Props | `ViewerSummaryV1`: `actor`, the viewer's actor id, and `issuer`, what the viewer's credential was issued for, `human`, `agent` or `service` |
| TypeScript | `ViewerSummaryV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.new-article`, which opens the form of `entry.create` (`Confirm::Form`) |

A contribution is an `ActionContribution`. Its command must be in the addon's `issues` and exposed on Inertia, and the button is shown only to a viewer who may run it. `prefill` fills members of the command's document from JSON pointers into the props, such as `['actor' => '/actor']`, and `confirm` says whether the button runs at once, asks first, shows a dry run first, or opens the command's form.

## Example

The fixture addon's button, compiled with its command:

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
