<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;

/**
 * The schemas a test gives, as cms:build would read them from the codecs and the point schema
 * directories. ContractSchemasBehaviour holds it to CodecContractSchemas.
 */
final readonly class FakeContractSchemas implements ContractSchemas
{
    public function __construct(private ContractShapes $shapes = new ContractShapes) {}

    public function shapes(): ContractShapes
    {
        return $this->shapes;
    }
}
