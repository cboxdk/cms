---
title: Action
weight: 22
description: "An action: a button the panel renders from the manifest's data, which runs a command of the addon's issues, with its prefill and its confirmation, and needs no code of the addon."
---

# Action

An action is a button the host renders with the kit. It runs one command, as the viewer, and needs no code of the addon: the manifest holds everything, so `cms:make:panel action` writes only the manifest line.

| | |
|---|---|
| Manifest | `new ActionContribution($id, '<point>@<version>', <command class>, '<label key>', icon: 'plus', prefill: ['<member>' => '<JSON pointer>'], confirm: Confirm::DryRun, tone: Tone::Neutral)` |
| Bundle | none |
| Receives | nothing; the host prefills the command's document from the point's props |
| Scaffold | `cms:make:panel action <namespace> <id> --point=<name>@<version> --command=<name>@<version>` |
| Test | `PanelContributionsContract` builds the manifest; `PanelVisit::assertFill()` finds the button in a browser test |
| Points | [`shell.user-menu@1`](../points/shell-user-menu.md) |

## What the host does

- The command must be in the addon's `issues` and exposed on Inertia, or `cms:build` refuses it with `registry_panel_command_not_issuable`.
- The button is shown only to a viewer who may run the command, so the panel never offers what the server would refuse.
- `prefill` fills members of the command's document from JSON pointers into the point's props; `cms:build` checks each pointer and member against both schemas (`registry_panel_action_prefill_invalid`).
- `confirm` says what happens on a press: `None` runs the command at once, `Confirm` asks in the kit's confirmation dialog, `DryRun` runs a dry run first and shows what would change before the viewer confirms, and `Form` opens the command's form.
- The run carries the provenance `addon:<namespace>:<contribution>`, and the receipt, or the problem details of a refusal, is shown where the button is.

## Example

The fixture addon's button of the viewer's menu, compiled by `cms:build`:

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
