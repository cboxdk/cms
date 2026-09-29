<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An expression of a TypeScript literal the generated validators hold their rules in (PRD 11.12):
 * LiteralPrinter prints it the way Prettier does, so the shared configuration accepts the
 * generated modules unchanged.
 */
#[Internal]
interface Literal
{
    /**
     * The literal on one line.
     */
    public function flat(): string;
}
