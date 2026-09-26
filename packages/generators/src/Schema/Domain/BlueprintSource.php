<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;

/**
 * Reads the blueprint files below schema roots into the typed blueprint model (PRD 11.12). A root
 * is a directory and the owner of every definition in it: the application's root is owner `app`,
 * and a module or addon has its own. The owner comes from where a file lies, never from the file.
 *
 * Every file below the roots is read in sorted path order and checked against the blueprint schema
 * v1, which is the only source of the rules for one file. The definitions read are then held to
 * BlueprintRules, the rules that JSON Schema cannot express. Every problem in every file is
 * collected before read() fails, so one run shows all of them.
 */
#[Internal]
interface BlueprintSource
{
    /**
     * @param  list<SchemaRoot>  $roots
     *
     * @throws GenerationFailed with generate_schema_missing for a root or a file that cannot be
     *                          read, generate_schema_invalid for a file that is not a valid
     *                          blueprint, generate_schema_unsupported_version for one that
     *                          needs a newer cboxdk/cms-generators, and the code of each rule of
     *                          BlueprintRules that the definitions break
     */
    public function read(array $roots): Blueprints;
}
