<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Points;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Panel\Boundary\Generated\Points\LoginNoticeCodecV1;
use Cbox\Cms\Panel\Login\Domain\Dto\LoginNoticeV1;
use Examples\Unit\Build\BuildTestCase;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * login.notice@1 in the workbench: cms:build compiles the fixture addon's LoginNotice
 * fixtureaddon.login-notice, a message of its catalogue in a tone. The login page is a credential
 * page that runs no addon code, so a notice is data the page renders itself, the point has no
 * props, and the kill switch turns the notice off at the next request.
 */
final class LoginNoticeTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_addon_s_notice_as_data(): void
    {
        self::assertSame(0, $this->build());
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'login.notice@1']));
        self::assertStringContainsString("1. fixtureaddon.login-notice  data  cboxdk/cms-fixture-addon, addon fixtureaddon\n     priority 1000 from the addon, enabled", app(Kernel::class)->output());

        config()->set('cbox-cms.panel.disabled.contributions', ['fixtureaddon.login-notice']);
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'login.notice@1']));
        self::assertStringContainsString('priority 1000 from the addon, disabled by the activation state', app(Kernel::class)->output());

        self::assertSame('{}', new LoginNoticeCodecV1()->encode(new LoginNoticeV1, ClassificationAccess::Public));
    }
}
