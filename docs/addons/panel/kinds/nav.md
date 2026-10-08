---
title: Nav entry
weight: 23
description: "A nav entry: an entry of the shell's navigation that opens a page of the addon, shown only to a viewer who may open the page, and also a page of the command palette."
---

# Nav entry

A nav entry is an entry of the shell's navigation. It opens a page of the same addon, and it is data: the shell renders it as the kit's `SideNav` and the command palette lists it among its pages.

| | |
|---|---|
| Manifest | `new NavContribution($id, 'shell.nav@1', '<label key>', '<page contribution id>', icon: 'menu', scope: new Scope(requires: new CommandName('<permission>')))` |
| Bundle | none |
| Receives | nothing |
| Scaffold | none; write the manifest line |
| Test | `PanelContributionsContract`; a browser test reads the navigation |
| Points | [`shell.nav@1`](../points/shell-nav.md) |

## Rules

- `page` is the id of a `PageContribution` of the same addon, or `cms:build` refuses it with `registry_panel_nav_target_unknown`.
- An entry is sent only when the viewer gets its page, so no entry leads to a page the viewer may not open.
- Entries render by priority within the navigation; the core's own are at 100, 200 and 300.

## Example

The fixture addon's entry, after the core's three:

<!-- example: examples/Unit/Panel/Points/ShellNavTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Points;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Panel\Boundary\Generated\Points\ShellNavCodecV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1;
use Examples\Unit\Build\BuildTestCase;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * shell.nav@1 in the workbench: cms:build compiles the fixture addon's NavContribution
 * fixtureaddon.articles-link, which opens the addon's page fixtureaddon.articles, onto the point
 * after the core's own entries at 100, 200 and 300, and its scope requires the permission
 * fixtureaddon.articles, so a viewer who may not open the page is never sent the entry. A nav
 * entry runs no code: the point has no props, and the shell renders the entry from its data.
 */
final class ShellNavTest extends BuildTestCase
{
    #[Test]
    public function it_lists_the_addon_s_nav_entry_after_the_core_s_own(): void
    {
        self::assertSame(0, $this->build());
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'shell.nav@1']));

        $output = app(Kernel::class)->output();
        self::assertStringContainsString('shell.nav@1: 4 contributions, in the order the host renders them', $output);
        self::assertStringContainsString('3. cms.grants  nav  cboxdk/cms, addon cms, scope requires grant.list', $output);
        self::assertStringContainsString('4. fixtureaddon.articles-link  nav  cboxdk/cms-fixture-addon, addon fixtureaddon, scope requires fixtureaddon.articles', $output);
        self::assertSame('{}', new ShellNavCodecV1()->encode(new ShellNavV1, ClassificationAccess::Public));
    }
}
```
