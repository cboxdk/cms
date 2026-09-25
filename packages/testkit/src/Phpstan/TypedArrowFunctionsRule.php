<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Rule 1 of GUARDRAILS 2.2 for the native parameter and return types of arrow functions. See DeclaredTypes.
 *
 * @implements Rule<InArrowFunctionNode>
 */
#[Internal]
final class TypedArrowFunctionsRule implements Rule
{
    public function getNodeType(): string
    {
        return InArrowFunctionNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return DeclaredTypes::errors($scope, DeclaredTypes::closure('arrow function', $scope, $node->getClosureType(), $node->getOriginalNode()));
    }
}
