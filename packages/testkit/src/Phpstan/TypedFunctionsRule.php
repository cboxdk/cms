<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InFunctionNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Rule 1 of GUARDRAILS 2.2 for the parameters, returns and template bounds of functions. See DeclaredTypes.
 *
 * @implements Rule<InFunctionNode>
 */
#[Internal]
final class TypedFunctionsRule implements Rule
{
    public function getNodeType(): string
    {
        return InFunctionNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return DeclaredTypes::errors($scope, DeclaredTypes::signature(
            sprintf('function %s()', $node->getFunctionReflection()->getName()),
            $node->getFunctionReflection()->getOnlyVariant(),
        ));
    }
}
