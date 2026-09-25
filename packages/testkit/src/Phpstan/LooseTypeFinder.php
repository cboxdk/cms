<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeTraverserCallable;

/**
 * Finds mixed, untyped arrays and array shapes in a declared type, at any depth: in unions,
 * intersections, generic arguments, iterables and callable signatures (GUARDRAILS 2.2).
 *
 * A typed array is list<T> or array<K, V> where neither K nor V is mixed; array<V> and V[]
 * have the key type int|string and count as typed. The element types are checked the same
 * way, so list<array> and array<string, Collection<int, mixed>> are loose. A template type
 * such as T is a generic parameter and is not loose.
 */
#[Internal]
final class LooseTypeFinder implements TypeTraverserCallable
{
    /** @var array<string, LooseType> */
    private array $found = [];

    private function __construct() {}

    /**
     * The loose types in the type of a parameter, a return or a property.
     *
     * A declaration without any type has an implicit mixed type. PHPStan reports that at
     * level 10 as missingType.*, so it is not reported twice here.
     *
     * @return list<LooseType>
     */
    public static function inDeclaration(Type $type): array
    {
        if ($type instanceof MixedType && ! $type->isExplicitMixed()) {
            return [];
        }

        return self::find($type);
    }

    /**
     * The loose types in the bound of a template. PHPStan gives an unbounded template the
     * bound mixed, so mixed at the top of a bound is not reported; mixed inside it is.
     *
     * @return list<LooseType>
     */
    public static function inBound(Type $bound): array
    {
        if ($bound instanceof MixedType) {
            return [];
        }

        return self::find($bound);
    }

    /**
     * The bounds of the templates of a function or a method, by template name.
     *
     * @param  array<string, Type>  $templateTypes
     * @return array<string, Type>
     */
    public static function boundsOf(array $templateTypes): array
    {
        $bounds = [];

        foreach ($templateTypes as $name => $templateType) {
            if ($templateType instanceof TemplateType) {
                $bounds[$name] = $templateType->getBound();
            }
        }

        return $bounds;
    }

    public function traverse(Type $type, callable $traverse): Type
    {
        if ($type instanceof TemplateType || $type instanceof ErrorType) {
            return $type;
        }

        if ($type instanceof MixedType) {
            $this->found[LooseType::Mixed->value] = LooseType::Mixed;

            return $type;
        }

        if ($type instanceof ConstantArrayType) {
            $this->found[LooseType::ArrayShape->value] = LooseType::ArrayShape;

            return $type;
        }

        if ($type instanceof ArrayType) {
            foreach ([$type->getKeyType(), $type->getItemType()] as $inner) {
                if ($this->isMixed($inner)) {
                    $this->found[LooseType::UntypedArray->value] = LooseType::UntypedArray;
                } else {
                    $this->traverse($inner, $traverse);
                }
            }

            return $type;
        }

        return $traverse($type);
    }

    /**
     * @return list<LooseType>
     */
    private static function find(Type $type): array
    {
        $finder = new self;
        TypeTraverser::map($type, $finder);

        return array_values(array_filter(
            LooseType::cases(),
            static fn (LooseType $loose): bool => isset($finder->found[$loose->value]),
        ));
    }

    private function isMixed(Type $type): bool
    {
        return $type instanceof MixedType && ! $type instanceof TemplateType && ! $type instanceof ErrorType;
    }
}
