<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Override;

/**
 * The registry cache in memory. It starts empty, so read() throws RegistryCacheMissing until
 * something is written. A test scripts the failures of the file cache: refuseWrites() makes every
 * write throw RegistryCacheUnwritable and keep what was there, and damage() makes read() throw
 * MalformedRegistryCache until the next write. RegistryCacheBehaviour holds it to
 * FileRegistryCache.
 */
final class FakeRegistryCache implements RegistryCache
{
    /** How many writes succeeded. */
    public int $writes = 0;

    private ?CompiledRegistry $stored = null;

    private ?string $refusal = null;

    private ?string $damage = null;

    public function __construct(private readonly string $directory = '/srv/app/bootstrap/cache/cms') {}

    #[Override]
    public function write(CompiledRegistry $registry): void
    {
        if ($this->refusal !== null) {
            throw RegistryCacheUnwritable::at($this->path(), $this->refusal);
        }

        $this->stored = $registry;
        $this->damage = null;
        $this->writes++;
    }

    #[Override]
    public function read(): CompiledRegistry
    {
        if (! $this->stored instanceof CompiledRegistry) {
            throw RegistryCacheMissing::at($this->path());
        }

        if ($this->damage !== null) {
            throw MalformedRegistryCache::at($this->path(), '', $this->damage);
        }

        return $this->stored;
    }

    #[Override]
    public function location(): string
    {
        return $this->directory;
    }

    /**
     * Every write from now on fails for the reason given.
     */
    public function refuseWrites(string $reason = 'Permission denied'): void
    {
        $this->refusal = $reason;
    }

    /**
     * The written cache cannot be read until the next write.
     */
    public function damage(string $problem = 'expected an array with the keys entries, format, registry, got string'): void
    {
        $this->damage = $problem;
    }

    /**
     * What the last successful write stored, or null before the first.
     */
    public function stored(): ?CompiledRegistry
    {
        return $this->stored;
    }

    /**
     * The file FileRegistryCache writes and reads first, which its failures name.
     */
    private function path(): string
    {
        return $this->directory.'/'.RegistryName::cases()[0]->fileName();
    }
}
