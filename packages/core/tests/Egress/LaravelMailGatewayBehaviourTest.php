<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Cms\Core\Egress\Domain\MailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\MailManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * MailGatewayBehaviour against the real gateway on Laravel's mail manager, with the mailer's
 * transport a SwitchableTransport. The gateway also takes the container's mailer, and a mail
 * without a sender, mail.from, fails as egress_mail_failed.
 */
final class LaravelMailGatewayBehaviourTest extends TestCase
{
    use MailGatewayBehaviour;

    private ?SwitchableTransport $transport = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $transport = new SwitchableTransport;
        $this->transport = $transport;
        $config = $this->app?->make(Repository::class);
        $config?->set('mail.mailers.switchable', ['transport' => 'switchable']);
        $config?->set('mail.default', 'switchable');
        $config?->set('mail.from', ['address' => 'cms@example.com', 'name' => 'Cbox CMS']);
        $this->app?->make(MailManager::class)->extend('switchable', static fn (): SwitchableTransport => $transport);
    }

    #[Override]
    protected function gateway(FakeTelemetry $telemetry): MailGateway
    {
        return new LaravelMailGateway($this->app?->make(MailManager::class)->mailer() ?? self::fail('No application.'), $telemetry);
    }

    #[Override]
    protected function breakTransport(): void
    {
        $this->transport()->breakDown();
    }

    #[Override]
    protected function delivered(): array
    {
        return array_map(static fn (Email $email): array => [
            implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo())),
            (string) $email->getSubject(),
            (string) $email->getTextBody(),
        ], $this->transport()->taken);
    }

    #[Test]
    public function the_container_gives_the_gateway_on_the_default_mailer_from_its_sender(): void
    {
        $this->app?->make(MailGateway::class)->send(new OutboundMail(new HostClass('probe'), new EmailAddress('ada@example.org'), 'A subject', 'The text'));

        $from = $this->transport()->taken[0]->getFrom()[0] ?? null;

        expect($from?->getAddress())->toBe('cms@example.com')
            ->and($this->transport()->taken[0]->getHtmlBody())->toBeNull();
    }

    #[Test]
    public function a_mail_without_a_sender_fails_with_egress_mail_failed(): void
    {
        $this->app?->make(Repository::class)->set('mail.from', ['address' => null, 'name' => null]);
        $this->app?->make(MailManager::class)->forgetMailers();

        expect(fn () => $this->gateway(new FakeTelemetry)->send(new OutboundMail(new HostClass('probe'), new EmailAddress('ada@example.org'), 'A subject', 'The text')))
            ->toThrow(EgressFailed::class, 'mail.from');
    }

    private function transport(): SwitchableTransport
    {
        return $this->transport ?? self::fail('The transport was not made.');
    }
}
