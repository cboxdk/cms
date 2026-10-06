<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Sample documents for the tests a scaffold writes: a document of a command and a result of a
 * data query, each from the JSON Schema of its codec. The port of ScaffoldContribution and
 * ScaffoldAddonUi; the generators bind it to Adapter\CodecDocumentSamples.
 */
#[Internal]
interface DocumentSamples
{
    /**
     * @throws GenerationFailed with generate_schema_missing when the command has no codec
     */
    public function command(CommandRef $command): Literal;

    /**
     * @throws GenerationFailed with generate_schema_missing when the query has no codec
     */
    public function result(CommandRef $query): Literal;
}
