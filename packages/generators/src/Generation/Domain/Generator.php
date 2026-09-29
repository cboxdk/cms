<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;

/**
 * One link of the type chain (PRD 11.12): turns the compiled schema, the descriptor of every type of
 * the schema roots with its extensions applied, into files. A generator reads the descriptors and
 * never a blueprint, so every link agrees on columns, types and rules.
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
     * "generated", or it is the target's migrations directory, because cms:generate deletes stale
     * files in it.
     */
    public function directory(GenerationTarget $target): string;

    /**
     * @return list<GeneratedFile> each below directory()
     *
     * @throws GenerationFailed
     */
    public function generate(CompiledSchema $schema, GenerationTarget $target): array;
}
