<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Rule 1 of GUARDRAILS 2.2 for the parameters, returns and template bounds of methods,
 * also abstract and interface methods. See DeclaredTypes.
 *
 * @implements Rule<InClassMethodNode>
 */
#[Internal]
final class TypedMethodsRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return DeclaredTypes::errors($scope, DeclaredTypes::signature(
            sprintf('method %s::%s()', $node->getClassReflection()->getDisplayName(), $node->getMethodReflection()->getName()),
            $node->getMethodReflection()->getOnlyVariant(),
        ));
    }
}
