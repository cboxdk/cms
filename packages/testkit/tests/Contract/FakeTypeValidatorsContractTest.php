<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;
use Cbox\Cms\Testkit\Tests\Validation\SampleValidator;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use Cbox\Cms\Testkit\Validation\TypeValidatorsContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeValidators contract suite against the in-memory fake, given the validators of the
 * two SampleTypes in the order opposite to their ids.
 */
final class FakeTypeValidatorsContractTest extends TestCase
{
    use TypeValidatorsContract;

    #[Override]
    protected function validators(): TypeValidators
    {
        return new FakeTypeValidators(SampleValidator::appNote(), SampleValidator::note());
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new FakeTypeCatalog(SampleTypes::note(), SampleTypes::appNote());
    }
}
