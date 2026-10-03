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
