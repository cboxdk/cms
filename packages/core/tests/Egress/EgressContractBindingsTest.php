<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Testkit\Egress\FakeEgressGateway;
use Cbox\Cms\Testkit\Egress\FakeMailGateway;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Attributes\Test;

/**
 * The egress and mail gateways are contracts (GUARDRAILS 2.3): the container gives the class
 * cbox-cms.contracts names, one singleton each, the core's adapters by default, and an
 * application swaps one entry at a time, such as the mail transport for the testkit's fake.
 */
final class EgressContractBindingsTest extends TestCase
{
    #[Test]
    public function the_defaults_are_the_cores_adapters_and_each_is_one_singleton(): void
    {
        $config = $this->app?->make(Repository::class);

        expect($config?->get(ContractBindings::CONFIG_KEY.'.'.EgressGateway::class))->toBe(SsrfEgressGateway::class)
            ->and($config?->get(ContractBindings::CONFIG_KEY.'.'.MailGateway::class))->toBe(LaravelMailGateway::class)
            ->and($this->app?->make(EgressGateway::class))->toBeInstanceOf(SsrfEgressGateway::class)
            ->and($this->app?->make(MailGateway::class))->toBeInstanceOf(LaravelMailGateway::class)
            ->and($this->app?->make(MailGateway::class))->toBe($this->app?->make(MailGateway::class));
    }

    #[Test]
    public function an_application_swaps_one_gateway_through_cbox_cms_contracts_and_keeps_the_other(): void
    {
        $this->app?->make(Repository::class)->set(ContractBindings::CONFIG_KEY.'.'.MailGateway::class, FakeMailGateway::class);

        $mail = $this->app?->make(MailGateway::class);
        $mail?->send(new OutboundMail(new HostClass('probe'), new EmailAddress('ada@example.org'), 'A subject', 'The text'));
        $fake = $this->app?->make(MailGateway::class);

        expect($fake)->toBeInstanceOf(FakeMailGateway::class)
            ->and($fake === $mail)->toBeTrue()
            ->and($this->recipients($fake))->toBe(['ada@example.org'])
            ->and($this->app?->make(EgressGateway::class))->toBeInstanceOf(SsrfEgressGateway::class);
    }

    #[Test]
    public function an_application_swaps_the_egress_gateway_through_cbox_cms_contracts(): void
    {
        $this->app?->make(Repository::class)->set(ContractBindings::CONFIG_KEY.'.'.EgressGateway::class, FakeEgressGateway::class);

        expect($this->app?->make(EgressGateway::class))->toBeInstanceOf(FakeEgressGateway::class)
            ->and($this->app?->make(MailGateway::class))->toBeInstanceOf(LaravelMailGateway::class);
    }

    /**
     * The recipients of the mails a FakeMailGateway took, in order.
     *
     * @return list<string>
     */
    private function recipients(?object $gateway): array
    {
        if (! $gateway instanceof FakeMailGateway) {
            return [];
        }

        return array_map(static fn (OutboundMail $sent): string => $sent->to->value, $gateway->sent());
    }
}
