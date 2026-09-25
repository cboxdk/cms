<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Every rule registered in the test container, run as one rule. The Internal*UsageExtension
 * classes are no rules of their own: PHPStan's restricted usage rules and its class name
 * checks in many rules call them. RuleTestCase runs a single rule, so this one runs them all,
 * and the tests look only at the cboxCms errors.
 *
 * @implements Rule<Node>
 */
final readonly class RegisteredRules implements Rule
{
    /**
     * @param  list<Rule<Node>>  $rules
     */
    public function __construct(private array $rules) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->rules as $rule) {
            $nodeType = $rule->getNodeType();

            if ($node instanceof $nodeType) {
                array_push($errors, ...$rule->processNode($node, $scope));
            }
        }

        return $errors;
    }
}
