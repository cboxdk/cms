<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;

/**
 * Where cms:generate reads the committed schema locks of the type tables (PRD 11.6): every
 * `<table>.lock` in the migrations directory.
 */
#[Internal]
interface SchemaLocks
{
    /**
     * The locks in the directory, sorted by table. A directory that does not exist has none.
     *
     * @param  string  $root  the absolute directory the migrations directory is relative to
     * @param  string  $directory  the migrations directory, relative to the root
     * @return list<TypeTableLock>
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid for every lock that cannot be
     *                          read, or GenerateErrorCode::SchemaMissing when the directory cannot be listed
     */
    public function read(string $root, string $directory): array;
}
