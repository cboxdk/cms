<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Login\Planted;

use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;
use Cbox\Cms\Testkit\Login\IssuerResolverContract;
use Cbox\Cms\Testkit\Login\IssuerResolverHarness;
use Closure;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * IssuerResolverContract run outside PHPUnit against a planted resolver, so a test can see which
 * of its cases fail. failing() runs every case of the suite and names those that fail an
 * assertion.
 */
final readonly class PlantedResolverSuite implements IssuerResolverHarness
{
    use IssuerResolverContract;

    /**
     * @param  Closure(IssuerPin ...): IssuerResolver  $plant
     */
    public function __construct(private Closure $plant) {}

    #[Override]
    public function resolver(IssuerPin ...$pins): IssuerResolver
    {
        return ($this->plant)(...$pins);
    }

    /**
     * @return list<string> the cases that fail, in the order of the suite
     */
    public function failing(): array
    {
        $failing = [];

        foreach (new ReflectionClass(self::class)->getMethods() as $method) {
            if ($method->getAttributes(Test::class) === []) {
                continue;
            }

            try {
                $method->invoke($this);
            } catch (AssertionFailedError) {
                $failing[] = $method->getName();
            }
        }

        return $failing;
    }

    #[Override]
    protected function issuers(): IssuerResolverHarness
    {
        return $this;
    }
}
