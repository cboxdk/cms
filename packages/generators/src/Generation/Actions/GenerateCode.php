<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;

/**
 * cms:generate (PRD 11.12, GUARDRAILS 7.1): reads the blueprint files below the target's schema
 * roots, applies the extensions to the types they extend, runs the generators and writes the
 * result. Nothing is written unless every blueprint is valid and every generator succeeded.
 */
#[Internal]
final readonly class GenerateCode
{
    public function __construct(
        private BlueprintSource $blueprints,
        private GeneratorRunner $runner,
        private GeneratedOutput $output,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function generate(GenerationTarget $target): WriteReport
    {
        $schema = SchemaResolver::resolve($this->blueprints->read($target->roots));

        return $this->output->write($target->root, $this->runner->run($schema, $target));
    }
}
