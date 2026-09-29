<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

#[Internal]
final readonly class BooleanLiteral implements Literal
{
    public function __construct(public bool $value) {}

    public function flat(): string
    {
        return $this->value ? 'true' : 'false';
    }
}
