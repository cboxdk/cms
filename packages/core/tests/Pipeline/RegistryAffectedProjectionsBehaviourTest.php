<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\RegistryAffectedProjections;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeNoted;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbePublished;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * AffectedProjectionsBehaviour against the registry the application reads, with the probe's
 * subscribers registered as cms:build registers them.
 */
final class RegistryAffectedProjectionsBehaviourTest extends TestCase
{
    use AffectedProjectionsBehaviour;

    #[Override]
    protected function affectedProjections(): AffectedProjections
    {
        $published = new SubscribedEvent(ProbePublished::class, ProbePublished::type());
        $noted = new SubscribedEvent(ProbeNoted::class, ProbeNoted::type());

        return new RegistryAffectedProjections(new CompiledRegistry([], [], [], [
            new SubscriberEntry('App\Origin', 'cboxdk/cms', new SubscriptionName('probe.origin'), Lane::Critical, new ProjectionName('origin'), [$published]),
            new SubscriberEntry('App\Search', 'cboxdk/cms', new SubscriptionName('probe.search'), Lane::Standard, new ProjectionName('search'), [$published]),
            new SubscriberEntry('App\OriginAgain', 'cboxdk/cms', new SubscriptionName('probe.origin_again'), Lane::Revalidate, new ProjectionName('origin'), [$published]),
            new SubscriberEntry('App\Webhooks', 'cboxdk/cms', new SubscriptionName('probe.webhooks'), Lane::External, null, [$noted]),
        ]));
    }
}
