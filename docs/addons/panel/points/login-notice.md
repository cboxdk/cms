---
title: "login.notice@1"
weight: 48
description: "Notices on the login page: a message of the addon's catalogue in a tone, which the page renders itself because a credential page runs no addon code."
---

# login.notice@1

<!-- extension-point: Cbox\Cms\Panel\Login\Domain\Dto\LoginNoticeV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/login.notice.v1.json -->

Notices above the form of the [login page](../pages.md#the-login-pages-notices), a credential page that runs no addon code (decision D13).

| | |
|---|---|
| Kind | [data](../kinds/data.md) |
| Page | `login`, at `<prefix>/login` |
| Props | none: `LoginNoticeV1` has no members |
| TypeScript | none needed: a notice runs no code |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.login-notice`, in the tone `Info` |

A contribution is a `LoginNotice`: the translation key of its message and a `Tone` (`Neutral`, `Info`, `Warning` or `Danger`). The page shows the enabled notices in render order, each as the kit's callout, with the text of the key in the page's locale, which the server reads from the addon's compiled catalogue, because a credential page carries no catalogue ([i18n](../../../ui/i18n.md#an-addons-texts)); a key the addon ships no text for shows as the key. The page has no viewer, so a notice is never held to a permission. The kill switch turns a notice off at the next request, and when the registry cannot be read the page shows none, so nothing an addon does keeps a person from the login form.

## Example

The fixture addon's notice, compiled, and turned off by the activation state:

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
