<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RegistryCacheBehaviour against the fake the registry's action tests use.
 */
final class FakeRegistryCacheBehaviourTest extends TestCase
{
    use RegistryCacheBehaviour;

    #[Override]
    protected function registryCache(): RegistryCache
    {
        return new FakeRegistryCache;
    }

    #[Override]
    protected function unwritableRegistryCache(): RegistryCache
    {
        $cache = new FakeRegistryCache;
        $cache->refuseWrites('mkdir(): Not a directory');

        return $cache;
    }

    #[Override]
    protected function damage(RegistryCache $cache): void
    {
        if (! $cache instanceof FakeRegistryCache) {
            throw new LogicException('The case damages a cache this class did not make.');
        }

        $cache->damage();
    }
}
