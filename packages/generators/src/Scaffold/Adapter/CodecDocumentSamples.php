<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\SampleProps;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Override;

/**
 * Sample documents from the JSON Schemas of the commands' and queries' codecs, as the stories of
 * the panel points sample their props.
 */
#[Internal]
final readonly class CodecDocumentSamples implements DocumentSamples
{
    public function __construct(
        private CommandCodecs $commands,
        private QueryCodecs $queries,
    ) {}

    #[Override]
    public function command(CommandRef $command): Literal
    {
        $codec = $this->commands->find($command->name, $command->version);

        if (! $codec instanceof CommandCodec) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The command %s has no codec, so its document has no JSON Schema to sample. Give the command a codec and run cms:build again.',
                $command->toString(),
            ));
        }

        return SampleProps::literal(SampleProps::of($codec->schema->json, 'the schema of the command '.$command->toString()));
    }

    #[Override]
    public function result(CommandRef $query): Literal
    {
        $codec = $this->queries->find($query->name, $query->version);

        if (! $codec instanceof QueryCodec) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The data query %s has no codec, so its result has no JSON Schema to sample. Give the query a codec and run cms:build again.',
                $query->toString(),
            ));
        }

        return SampleProps::literal(SampleProps::of($codec->resultSchema->json, 'the schema of the result of the query '.$query->toString()));
    }
}
