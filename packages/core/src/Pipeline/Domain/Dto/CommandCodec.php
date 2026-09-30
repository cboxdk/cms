<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;

/**
 * The JSON codec of one version of a command (GUARDRAILS 2.2): what an exposed surface reads a
 * caller's command with, and the JSON Schema of the document it reads, which cms:build puts in the
 * OpenAPI document of the REST surface as the command's request body (PRD 8.8). The codec is
 * generated from the command's contract version; the command's name and version are those of its
 * #[Command].
 */
#[Experimental]
final readonly class CommandCodec
{
    /**
     * @param  JsonCodec<covariant Command>  $codec
     */
    public function __construct(
        public CommandName $command,
        public int $version,
        public JsonCodec $codec,
        public JsonSchema $schema,
    ) {
        if ($version < 1) {
            throw UnknownCommand::version($command->value, $version);
        }
    }
}
