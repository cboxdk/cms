<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The command a codec reads (GUARDRAILS 2.1, 2.2): the name of its #[Command], without the
 * version, which is the contract's, and the text of the JSON Schema of its document, which the
 * codec carries so that every exposed surface describes the command with it (PRD 8.8, 14.5).
 */
#[Internal]
final readonly class CodecCommand
{
    /**
     * @param  string  $name  the command's name, such as entry.create
     * @param  string  $schema  the JSON Schema of the command's document, pretty-printed JSON
     */
    public function __construct(
        public string $name,
        public string $schema,
    ) {}
}
