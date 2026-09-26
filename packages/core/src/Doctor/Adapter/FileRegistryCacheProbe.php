<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\RegistryCacheState;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use DateTimeImmutable;
use Override;

/**
 * Reads the modification times of the registry files and of Composer's installed.json, and
 * whether the files can be read. Times are whole seconds, as the filesystem gives them to PHP; a
 * cache written in the same second as installed.json counts as not older.
 */
#[Internal]
final readonly class FileRegistryCacheProbe implements RegistryCacheProbe
{
    public function __construct(
        private RegistryCache $cache,
        private string $vendorManifest,
    ) {}

    #[Override]
    public function state(): RegistryCacheState
    {
        clearstatcache();

        $location = $this->cache->location();
        $missing = [];
        $oldest = null;

        foreach (RegistryName::cases() as $name) {
            $modified = $this->modified($location.'/'.$name->fileName());

            if ($modified === null) {
                $missing[] = $name->fileName();

                continue;
            }

            $oldest = $oldest === null ? $modified : min($oldest, $modified);
        }

        $damage = null;

        if ($missing === []) {
            try {
                $this->cache->read();
            } catch (MalformedRegistryCache|RegistryCacheMissing $unreadable) {
                $damage = $unreadable->getMessage();
            }
        }

        $manifest = $this->modified($this->vendorManifest);

        return new RegistryCacheState(
            location: $location,
            missingFiles: $missing,
            builtAt: $missing === [] && $oldest !== null ? $this->instant($oldest) : null,
            damage: $damage,
            manifest: $this->vendorManifest,
            manifestChangedAt: $manifest === null ? null : $this->instant($manifest),
        );
    }

    private function modified(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $time = filemtime($path);

        return $time === false ? null : $time;
    }

    private function instant(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$timestamp);
    }
}
