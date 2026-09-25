<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedMethodsRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The control in the tests: the errors of TypedMethodsRule rebuilt as ordinary, ignorable
 * errors. An ignore comment that targets the reported line hides them, which proves that the
 * comments in the fixture do target those lines.
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class IgnorableTypedMethodsRule implements Rule
{
    public function __construct(private TypedMethodsRule $rule = new TypedMethodsRule) {}

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return array_map(
            static fn (IdentifierRuleError $error): IdentifierRuleError => RuleErrorBuilder::message($error->getMessage())
                ->identifier($error->getIdentifier())
                ->build(),
            $this->rule->processNode($node, $scope),
        );
    }
}
