<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Editor\Domain\Dto\SchemaFile;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;

/**
 * The blueprint files that cms:schema:editor edits: the same files below the schema roots that
 * cms:generate reads.
 */
#[Internal]
interface SchemaFiles
{
    /**
     * Every `*.yaml` file below the roots, at any depth, sorted by SchemaFile::$file.
     *
     * @param  list<SchemaRoot>  $roots
     * @return list<SchemaFile>
     *
     * @throws GenerationFailed with generate_invalid_config when one root lies in another, and
     *                          generate_schema_missing when a root or a directory below it cannot
     *                          be read
     */
    public function find(array $roots): array;

    /**
     * The bytes of the file.
     *
     * @throws GenerationFailed with generate_schema_missing when the file cannot be read
     */
    public function read(SchemaFile $file): string;

    /**
     * Replaces the bytes of the file, all or nothing, and keeps its permissions. A symlink stays a
     * symlink, and the file it points at is written.
     *
     * @throws GenerationFailed with generate_schema_unwritable when the file cannot be written; it then keeps its bytes
     */
    public function write(SchemaFile $file, string $contents): void;
}
