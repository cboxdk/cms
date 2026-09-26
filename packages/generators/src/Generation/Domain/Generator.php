<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;

/**
 * One link of the type chain (PRD 11.12): turns the resolved schema, the types of all schema roots
 * with their extensions applied, into files.
 *
 * Internal to the generators package in milestone 0. It is not a contract in Cbox\Cms\Contracts,
 * so no fake is owed (GUARDRAILS 2.3); milestone 1 decides whether addons get a generator
 * contract.
 *
 * A generator is a pure function of the schema and the target: the same input gives the same
 * bytes, with no timestamps, no absolute paths and nothing that depends on the order of the
 * blueprint files. It owns its directory: cms:generate removes every file there that no generator
 * produced.
 */
#[Internal]
interface Generator
{
    /**
     * The directory the generator owns, relative to the root. Its last segment is "Generated" or
     * "generated", because cms:generate deletes stale files in it.
     */
    public function directory(GenerationTarget $target): string;

    /**
     * @return list<GeneratedFile> each below directory()
     *
     * @throws GenerationFailed
     */
    public function generate(ResolvedSchema $schema, GenerationTarget $target): array;
}
