---
title: Mail gateway
weight: 49
description: "The MailGateway contract: the one way out for mail, its default LaravelMailGateway on the default mailer, how an application replaces the transport, the testkit's FakeMailGateway and the shared suite MailGatewayContract with its harness."
---

# Mail gateway

<!-- extension-point: Cbox\Cms\Contracts\Egress\MailGateway -->
<!-- extension-point: Cbox\Cms\Testkit\Egress\MailGatewayHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Egress\MailGatewayContract -->

Every mail of the kernel, its modules and its addons goes through `Cbox\Cms\Contracts\Egress\MailGateway` (GUARDRAILS 3), as HTTP goes through the [egress gateway](egress-gateway.md). It is `#[Experimental]`. Why the SSRF guard does not apply to mail is on [Egress](../../security/egress.md#mail).

## The contract

`send(OutboundMail $mail): void` hands one mail to the transport. An `OutboundMail` is a `HostClass` to count it under, such as `password_reset`, one recipient, a subject of one line without control characters and a plain text; the recipient and the text never show in a dump or a stack trace. A mail the transport refuses or does not take, and a mail without a sender, fail with `EgressFailed` and [`egress_mail_failed`](../../reference/errors.md#egress_mail_failed), whose message does not name the recipient and which chains no exception. Every mail counts under `MailGateway::MAILS`, and every failed one also under `EgressGateway::FAILURES`, with the attributes `EgressGateway::HOST_CLASS` and `EgressGateway::OUTCOME` (`ok` or `unavailable`) only.

## The default and replacing it

`cbox-cms.contracts` binds the contract to the core's `Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway`, which hands the mail to the transport of Laravel's default mailer, `mail.default`, from `mail.from`, unless the application names another class; see [Configuration](../../developers/configuration.md#contracts). Most applications choose the transport through Laravel's own `mail.mailers` and keep the default; a replacement of the contract passes the shared suite below.

## The fake: FakeMailGateway

`Cbox\Cms\Testkit\Egress\FakeMailGateway` sends nothing. It keeps every mail it takes, in order, in `sent()`, until `goDown()`, after which it takes none and fails as a gateway fails when the transport refuses a mail. It counts on the telemetry it is given as a gateway counts. This example is in the `Unit` suite:

<!-- example: examples/Unit/Egress/FakeMailGatewayTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Testkit\Egress\FakeMailGateway;

// An addon mails a weekly digest to an editor through the mail gateway the container gives. The
// test binds the testkit's FakeMailGateway in its place, so nothing reaches a transport, reads the
// mail back from the fake, and shows that the addon reports a transport that is down.

it('mails the digest through the gateway and reports a transport that is down', function (): void {
    $gateway = new FakeMailGateway;
    app()->instance(MailGateway::class, $gateway);
    // What the addon does: send the digest through the MailGateway the container gives.
    $digest = static function (string $editor): bool {
        try {
            app(MailGateway::class)->send(new OutboundMail(
                new HostClass('digest'),
                new EmailAddress($editor),
                'Your weekly digest',
                "Three entries were published this week.\n",
            ));
        } catch (EgressFailed) {
            return false;
        }

        return true;
    };

    expect($digest('editor@example.org'))->toBeTrue()
        ->and(array_map(static fn (OutboundMail $mail): string => $mail->to->value, $gateway->sent()))->toBe(['editor@example.org'])
        ->and($gateway->sent()[0]->subject)->toBe('Your weekly digest');

    $gateway->goDown();

    expect($digest('editor@example.org'))->toBeFalse()
        ->and($gateway->sent())->toHaveCount(1);
});
```

## Running the shared suite against an implementation

Every implementation runs the shared suite, the trait `Cbox\Cms\Testkit\Egress\MailGatewayContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `harness(): MailGatewayHarness`, which returns a fresh harness for each case. The harness gives the transport's side: `gateway(FakeTelemetry $telemetry)` is the implementation under test counting on that telemetry, `breakTransport()` makes the transport take no mail from now on, and `delivered()` lists each mail the transport took as its recipient, subject and text. The core's harness for `LaravelMailGateway` sets a default mailer whose transport keeps every mail it takes, so no mail leaves the test.

The cases cover mails handed to their recipients with their subjects and texts, a transport that does not take the mail, failures that never name the recipient, and the counters of each.
