<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Testkit\Egress\MailGatewayHarness;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Override;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The harness of the shared MailGatewayContract for the real gateway on Laravel's mail manager:
 * the default mailer is `switchable`, whose transport is a SwitchableTransport that keeps every
 * mail it takes, so no mail leaves the test, with the sender cms@example.com in mail.from.
 */
final readonly class LaravelMailGatewayHarness implements MailGatewayHarness
{
    public SwitchableTransport $transport;

    public function __construct(private Application $app)
    {
        $transport = new SwitchableTransport;
        $this->transport = $transport;
        $config = $app->make(Repository::class);
        $config->set('mail.mailers.switchable', ['transport' => 'switchable']);
        $config->set('mail.default', 'switchable');
        $config->set('mail.from', ['address' => 'cms@example.com', 'name' => 'Cbox CMS']);
        $manager = $app->make(MailManager::class);
        $manager->extend('switchable', static fn (): SwitchableTransport => $transport);
        $manager->forgetMailers();
    }

    #[Override]
    public function gateway(FakeTelemetry $telemetry): MailGateway
    {
        return new LaravelMailGateway($this->app->make(MailManager::class)->mailer(), $telemetry);
    }

    #[Override]
    public function breakTransport(): void
    {
        $this->transport->breakDown();
    }

    #[Override]
    public function delivered(): array
    {
        return array_map(static fn (Email $email): array => [
            implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo())),
            (string) $email->getSubject(),
            (string) $email->getTextBody(),
        ], $this->transport->taken);
    }
}
