---
title: "shell.nav@1"
weight: 41
description: "The navigation of the panel's shell: an entry that opens a page of the addon, shown only to a viewer who may open the page."
---

# shell.nav@1

<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.nav.v1.json -->

The navigation of the [shell](../shell.md), on every page behind the login. An entry opens a page of its own addon, and the command palette lists the same entries as its pages.

| | |
|---|---|
| Kind | [nav](../kinds/nav.md) |
| Page | `shell`, rendered on every page behind the login |
| Props | none: `ShellNavV1` has no members |
| TypeScript | `ShellNavV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | `cms.account-me` at 100, `cms.roles` at 200, requiring `role.list`, and `cms.grants` at 300, requiring `grant.list` |
| Fixture addon | `fixtureaddon.articles-link`, which opens `fixtureaddon.articles` and requires `fixtureaddon.articles` |

A contribution is a `NavContribution` with its label, a key of its addon's catalogue, its icon and the id of its addon's `PageContribution`. It is sent only to a viewer who gets the page, so no entry leads to a page the viewer may not open, and an entry the viewer may not see is never sent, not even its id.

## Example

The fixture addon's entry, compiled after the core's:

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
