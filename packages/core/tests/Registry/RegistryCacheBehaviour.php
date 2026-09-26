<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * What every RegistryCache does (PRD 13.2), run against FileRegistryCache in a scratch directory
 * and against FakeRegistryCache, so the fake the registry's action tests use cannot drift from
 * the file cache (GUARDRAILS 9).
 */
trait RegistryCacheBehaviour
{
    /**
     * A cache that nothing has been written to, and that can be written.
     */
    abstract protected function registryCache(): RegistryCache;

    /**
     * A cache that nothing has been written to, and whose writes fail.
     */
    abstract protected function unwritableRegistryCache(): RegistryCache;

    /**
     * Damages what was written to the cache, so reading it fails.
     */
    abstract protected function damage(RegistryCache $cache): void;

    #[Test]
    public function it_refuses_to_read_before_anything_was_written(): void
    {
        $cache = $this->registryCache();
        $missing = $this->thrownBy(static fn (): CompiledRegistry => $cache->read());

        Assert::assertInstanceOf(RegistryCacheMissing::class, $missing);
        Assert::assertStringStartsWith('['.RegistryCacheMissing::CODE.'] The registry cache file '.$cache->location().'/', $missing->getMessage());
    }

    #[Test]
    public function it_reads_back_the_registry_that_was_written(): void
    {
        $cache = $this->registryCache();
        $registry = $this->fixtureRegistry();

        $cache->write($registry);

        Assert::assertEquals($registry, $cache->read());
        Assert::assertEquals($registry, $cache->read(), 'Reading again gives the same registry.');
    }

    #[Test]
    public function a_write_replaces_the_registry_written_before(): void
    {
        $cache = $this->registryCache();
        $cache->write($this->fixtureRegistry());

        $cache->write(CompiledRegistry::empty());

        Assert::assertEquals(CompiledRegistry::empty(), $cache->read());
    }

    #[Test]
    public function a_write_that_fails_throws_registry_cache_unwritable_and_leaves_nothing_to_read(): void
    {
        $cache = $this->unwritableRegistryCache();

        $unwritable = $this->thrownBy(fn () => $cache->write($this->fixtureRegistry()));

        Assert::assertInstanceOf(RegistryCacheUnwritable::class, $unwritable);
        Assert::assertStringStartsWith('['.RegistryCacheUnwritable::CODE.'] Could not write the registry cache file '.$cache->location(), $unwritable->getMessage());
        Assert::assertInstanceOf(RegistryCacheMissing::class, $this->thrownBy(static fn (): CompiledRegistry => $cache->read()));
    }

    #[Test]
    public function a_damaged_cache_throws_malformed_registry_cache_until_it_is_written_again(): void
    {
        $cache = $this->registryCache();
        $cache->write($this->fixtureRegistry());
        $this->damage($cache);

        $malformed = $this->thrownBy(static fn (): CompiledRegistry => $cache->read());

        Assert::assertInstanceOf(MalformedRegistryCache::class, $malformed);
        Assert::assertStringStartsWith('['.MalformedRegistryCache::CODE.'] The registry cache file '.$cache->location().'/', $malformed->getMessage());

        $cache->write($this->fixtureRegistry());

        Assert::assertEquals($this->fixtureRegistry(), $cache->read());
    }

    private function fixtureRegistry(): CompiledRegistry
    {
        return new RegistryCompiler()->compile(RegistryFixtures::validDiscovery());
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function thrownBy(Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        Assert::fail('Nothing was thrown.');
    }
}
