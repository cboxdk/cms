<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;

/**
 * cms:generate (PRD 11.12, GUARDRAILS 7.1): reads the schema, runs the generators and writes the
 * result. Nothing is written unless the schema is valid and every generator succeeded.
 */
#[Internal]
final readonly class GenerateCode
{
    public function __construct(
        private SchemaSource $schemas,
        private GeneratorRunner $runner,
        private GeneratedOutput $output,
    ) {}

    /**
     * @throws GenerationFailed
     */
    public function generate(GenerationTarget $target): WriteReport
    {
        $schema = $this->schemas->load($target->root.'/'.$target->schema);

        return $this->output->write($target->root, $this->runner->run($schema, $target));
    }
}
