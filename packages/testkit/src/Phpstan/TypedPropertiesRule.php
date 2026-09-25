<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Rule 1 of GUARDRAILS 2.2 for properties. See DeclaredTypes.
 *
 * A promoted property is a constructor parameter as well and is checked there, by
 * TypedMethodsRule. A property without a native or PHPDoc type is PHPStan's own
 * missingType.property error.
 *
 * @implements Rule<ClassPropertyNode>
 */
#[Internal]
final class TypedPropertiesRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $type = $node->getPhpDocType() ?? $node->getNativeType();

        if ($node->isPromoted() || $type === null) {
            return [];
        }

        return DeclaredTypes::errors($scope, [new Declaration(
            sprintf('Property %s::$%s', $node->getClassReflection()->getDisplayName(), $node->getName()),
            $type,
        )]);
    }
}
