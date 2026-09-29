<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A number, written as its literal, such as `12` or `-Infinity`.
 */
#[Internal]
final readonly class NumberLiteral implements Literal
{
    public function __construct(public string $literal) {}

    public static function of(int $value): self
    {
        return new self((string) $value);
    }

    public function flat(): string
    {
        return $this->literal;
    }
}
