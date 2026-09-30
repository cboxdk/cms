<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Tests\Delivery\Fakes\FakeDeliveryDocuments;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DeliveryDocumentsBehaviour against the fake the delivery's action tests use.
 */
final class FakeDeliveryDocumentsBehaviourTest extends TestCase
{
    use DeliveryDocumentsBehaviour;

    #[Override]
    protected function documents(): DeliveryDocuments
    {
        return new FakeDeliveryDocuments;
    }
}
