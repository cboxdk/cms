<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\Type;

/**
 * Rule 1 of GUARDRAILS 2.2 for class constants. See DeclaredTypes.
 *
 * The declared type of a constant is the type in its PHPDoc var tag, or else its native type,
 * so a `const array` without a typed var tag is an untyped array like an `array` property. A constant
 * without a native or PHPDoc type declares nothing: PHPStan takes the exact type of its value,
 * which is never mixed.
 *
 * @implements Rule<ClassConst>
 */
#[Internal]
final class TypedClassConstantsRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassConst::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (! $class instanceof ClassReflection) {
            return [];
        }

        $declarations = [];

        foreach ($node->consts as $const) {
            $name = $const->name->toString();

            if (! $class->hasConstant($name)) {
                continue;
            }

            $constant = $class->getConstant($name);
            $type = $constant->getPhpDocType() ?? ($constant->hasNativeType() ? $constant->getNativeType() : null);

            if ($type instanceof Type) {
                $declarations[] = new Declaration(sprintf('Constant %s::%s', $class->getDisplayName(), $name), $type);
            }
        }

        return DeclaredTypes::errors($scope, $declarations);
    }
}
