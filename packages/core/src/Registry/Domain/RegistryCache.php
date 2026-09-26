<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The compiled registry on disk, in bootstrap/cache/cms/ (PRD 13.2): one PHP file per registry,
 * sorted and without timestamps, so two builds from the same code give the same bytes.
 */
#[Experimental]
interface RegistryCache
{
    /**
     * Writes all six files. Each file is replaced as a whole, so a reader never sees half a file.
     *
     * @throws RegistryCacheUnwritable
     */
    public function write(CompiledRegistry $registry): void;

    /**
     * Reads the six files into typed entries.
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
