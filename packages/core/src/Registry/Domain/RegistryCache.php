<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The compiled registry on disk, in bootstrap/cache/cms/ (PRD 13.2): one PHP file per registry,
 * sorted and without timestamps, so two builds from the same code give the same bytes.
 */
#[Internal]
interface RegistryCache
{
    /**
     * Writes one file per RegistryName and then removes every other file in the directory, which
     * the cache owns, so a registry that is no longer written does not linger. Each file is
     * replaced as a whole, so a reader never sees half a file.
     *
     * @throws RegistryCacheUnwritable
     */
    public function write(CompiledRegistry $registry): void;

    /**
     * Reads the file of each RegistryName into typed entries, all from one build: a read while a
     * write replaces the files gives the whole old or the whole new registry, and files that stay
     * from different builds are MalformedRegistryCache.
     *
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function read(): CompiledRegistry;

    /**
     * The directory the files live in.
     */
    public function location(): string;
}
