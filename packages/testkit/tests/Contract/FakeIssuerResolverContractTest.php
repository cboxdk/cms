<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Cbox\Cms\Testkit\Login\IssuerResolverContract;
use Cbox\Cms\Testkit\Login\IssuerResolverHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IssuerResolver contract suite against the fake.
 */
final class FakeIssuerResolverContractTest extends TestCase
{
    use IssuerResolverContract;

    #[Override]
    protected function issuers(): IssuerResolverHarness
    {
        return new FakeIssuerResolver;
    }
}
