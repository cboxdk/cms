<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

#[Internal]
final readonly class ArrayLiteral implements Literal
{
    /**
     * @param  list<Literal>  $items
     */
    public function __construct(public array $items) {}

    /**
     * An array of strings.
     *
     * @param  list<string>  $values
     */
    public static function strings(array $values): self
    {
        return new self(array_map(static fn (string $value): StringLiteral => new StringLiteral($value), $values));
    }

    public function flat(): string
    {
        return '['.implode(', ', array_map(static fn (Literal $item): string => $item->flat(), $this->items)).']';
    }
}
