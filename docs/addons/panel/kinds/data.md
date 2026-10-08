---
title: Data
weight: 32
description: "A data point: plain data a page renders itself, such as a notice on the login page, where no addon code runs."
---

# Data

A data point takes plain data that the page renders itself. It is for the pages that run no addon code, the credential pages, so a contribution there holds no code and loads nothing of its addon.

| | |
|---|---|
| Manifest | `new LoginNotice($id, 'login.notice@1', '<message key>', Tone::Info)` |
| Bundle | none |
| Receives | nothing |
| Scaffold | none; write the manifest line |
| Test | `PanelContributionsContract`; a browser test reads the notice |
| Points | [`login.notice@1`](../points/login-notice.md) |

## Rules

- The login page has no viewer, so a notice is never held to a permission: every enabled notice is shown, in render order.
- The kill switch turns a notice off at the next request, and when the registry cannot be read the page shows none, so nothing an addon does keeps a person from the login form.

## Example

The fixture addon's notice on the login page:

<!-- example: examples/Unit/Panel/Points/LoginNoticeTest.php -->
```php
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
```
