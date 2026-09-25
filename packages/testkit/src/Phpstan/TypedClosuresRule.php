<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClosureNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Rule 1 of GUARDRAILS 2.2 for the native parameter and return types of closures. See DeclaredTypes.
 *
 * @implements Rule<InClosureNode>
 */
#[Internal]
final class TypedClosuresRule implements Rule
{
    public function getNodeType(): string
    {
        return InClosureNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return DeclaredTypes::errors($scope, DeclaredTypes::closure('closure', $scope, $node->getClosureType(), $node->getOriginalNode()));
    }
}
