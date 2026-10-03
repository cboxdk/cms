<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\MailManager;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * What LaravelMailGateway does beyond the shared MailGatewayContract: the container gives it on
 * the default mailer from its sender, as plain text, and a mail without a sender, mail.from, fails
 * as egress_mail_failed.
 */
final class LaravelMailGatewayTest extends TestCase
{
    private ?LaravelMailGatewayHarness $harness = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new LaravelMailGatewayHarness($this->app ?? self::fail('No application.'));
    }

    #[Test]
    public function the_container_gives_the_gateway_on_the_default_mailer_from_its_sender(): void
    {
        $gateway = $this->app?->make(MailGateway::class);
        $gateway?->send(new OutboundMail(new HostClass('probe'), new EmailAddress('ada@example.org'), 'A subject', 'The text'));

        $taken = $this->harness()->transport->taken;
        $from = $taken[0]->getFrom()[0] ?? null;

        expect($gateway)->toBeInstanceOf(LaravelMailGateway::class)
            ->and($from?->getAddress())->toBe('cms@example.com')
            ->and($taken[0]->getHtmlBody())->toBeNull();
    }

    #[Test]
    public function a_mail_without_a_sender_fails_with_egress_mail_failed(): void
    {
        $this->app?->make(Repository::class)->set('mail.from', ['address' => null, 'name' => null]);
        $this->app?->make(MailManager::class)->forgetMailers();

        expect(fn () => $this->harness()->gateway(new FakeTelemetry)->send(new OutboundMail(new HostClass('probe'), new EmailAddress('ada@example.org'), 'A subject', 'The text')))
            ->toThrow(EgressFailed::class, 'mail.from');
    }

    private function harness(): LaravelMailGatewayHarness
    {
        return $this->harness ?? self::fail('The harness was not made.');
    }
}
