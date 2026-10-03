<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A TypeScript type as cms:panel:types prints it: a name or keyword, a generic with its arguments
 * such as Lazy<SlotComponent<A>>, a union of members, or a readonly array of an element. The
 * printer lays it out as Prettier does, so the generated module passes Prettier unchanged.
 */
#[Internal]
final readonly class TypeExpression
{
    /**
     * @param  list<TypeExpression>  $arguments  a generic's arguments, or a union's members
     */
    private function __construct(
        public string $text,
        public array $arguments,
        public bool $union,
        public ?TypeExpression $element,
    ) {}

    public static function atom(string $text): self
    {
        return new self($text, [], false, null);
    }

    /**
     * @param  list<TypeExpression>  $arguments
     */
    public static function generic(string $name, array $arguments): self
    {
        return new self($name, $arguments, false, null);
    }

    /**
     * The union of the members, each once by its flat text, or the one member there is.
     *
     * @param  list<TypeExpression>  $members
     */
    public static function union(array $members): self
    {
        $unique = [];

        foreach ($members as $member) {
            foreach ($member->union ? $member->arguments : [$member] as $each) {
                $unique[$each->flat()] ??= $each;
            }
        }

        $unique = array_values($unique);

        return count($unique) === 1 ? $unique[0] : new self('', $unique, true, null);
    }

    public static function array(self $element): self
    {
        return new self('', [], false, $element);
    }

    /**
     * The type on one line.
     */
    public function flat(): string
    {
        if ($this->element instanceof self) {
            return 'readonly '.($this->element->union ? '('.$this->element->flat().')' : $this->element->flat()).'[]';
        }

        if ($this->union) {
            return implode(' | ', array_map(static fn (self $member): string => $member->flat(), $this->arguments));
        }

        return $this->arguments === []
            ? $this->text
            : $this->text.'<'.implode(', ', array_map(static fn (self $argument): string => $argument->flat(), $this->arguments)).'>';
    }
}
