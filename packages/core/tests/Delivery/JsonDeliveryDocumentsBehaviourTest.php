<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Core\Delivery\Adapter\JsonDeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DeliveryDocumentsBehaviour against the documents the delivery API sends.
 */
final class JsonDeliveryDocumentsBehaviourTest extends TestCase
{
    use DeliveryDocumentsBehaviour;

    #[Override]
    protected function documents(): DeliveryDocuments
    {
        return new JsonDeliveryDocuments;
    }
}
