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
