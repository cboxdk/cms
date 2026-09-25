<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Type\Type;

/**
 * One declared type that TypedDeclarationsRule checks: a parameter, a return, a property,
 * a template bound or a type in a class PHPDoc tag.
 */
#[Internal]
final readonly class Declaration
{
    /**
     * @param  string  $subject  what is declared, for example "Parameter $value of method Foo::bar()"
     * @param  bool  $isBound  true for a template bound, where mixed at the top means unbounded
     */
    public function __construct(
        public string $subject,
        public Type $type,
        public bool $isBound = false,
    ) {}

    /**
     * @return list<LooseType>
     */
    public function looseTypes(): array
    {
        return $this->isBound
            ? LooseTypeFinder::inBound($this->type)
            : LooseTypeFinder::inDeclaration($this->type);
    }
}
