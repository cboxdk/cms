<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\SentMessage;

// An addon mails a weekly digest to an editor. It asks the container for the mail gateway and
// sends the mail through it, in plain text, from the installation's sender, mail.from. The test
// sets Laravel's array mailer as mail.default, so nothing leaves the test, and reads the mail back
// from its transport.

it('sends a mail through the mail gateway on the default mailer', function (): void {
    config([
        'mail.default' => 'array',
        'mail.from' => ['address' => 'cms@example.com', 'name' => 'Cbox CMS'],
    ]);

    // What the addon does: send the mail through the MailGateway the container gives.
    $digest = static function (MailGateway $mail, string $editor): void {
        $mail->send(new OutboundMail(
            new HostClass('digest'),
            new EmailAddress($editor),
            'Your weekly digest',
            "Three entries were published this week.\n",
        ));
    };

    $digest(app(MailGateway::class), 'editor@example.org');

    $transport = app(MailManager::class)->mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);
    $sent = $transport->messages()->first();
    assert($sent instanceof SentMessage);

    expect($sent->getEnvelope()->getRecipients()[0]->getAddress())->toBe('editor@example.org')
        ->and($sent->getEnvelope()->getSender()->getAddress())->toBe('cms@example.com')
        ->and($sent->toString())->toContain('Subject: Your weekly digest');
});
