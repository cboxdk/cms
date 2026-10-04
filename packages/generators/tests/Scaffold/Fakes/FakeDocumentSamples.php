<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold\Fakes;

use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Override;

/**
 * Sample documents a test gives, by command and by query: what the scaffold actions' tests put
 * in the stubs. A command or query the test gave none for has no codec, as far as the fake knows,
 * and is refused as CodecDocumentSamples refuses it. DocumentSamplesBehaviour holds it to that.
 */
final class FakeDocumentSamples implements DocumentSamples
{
    /** @var array<string, Literal> */
    private array $commands = [];

    /** @var array<string, Literal> */
    private array $results = [];

    /**
     * Gives the command a sample document.
     */
    public function withCommand(CommandRef $command, Literal $sample): self
    {
        $this->commands[$command->toString()] = $sample;

        return $this;
    }

    /**
     * Gives the data query a sample result.
     */
    public function withResult(CommandRef $query, Literal $sample): self
    {
        $this->results[$query->toString()] = $sample;

        return $this;
    }

    #[Override]
    public function command(CommandRef $command): Literal
    {
        return $this->commands[$command->toString()] ?? throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
            'The command %s has no codec, so its document has no JSON Schema to sample. Give the command a codec and run cms:build again.',
            $command->toString(),
        ));
    }

    #[Override]
    public function result(CommandRef $query): Literal
    {
        return $this->results[$query->toString()] ?? throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
            'The data query %s has no codec, so its result has no JSON Schema to sample. Give the query a codec and run cms:build again.',
            $query->toString(),
        ));
    }
}
