<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The control in the tests: PhpstanIgnoreRule as it would be with ordinary, ignorable
 * errors. It shows which ignore comments would hide the error about themselves.
 *
 * @implements Rule<CollectedDataNode>
 */
final class IgnorablePhpstanIgnoreRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->get(PhpstanIgnoreCollector::class) as $file => $collected) {
            foreach ($collected as $lines) {
                foreach ($lines as $line) {
                    $errors[] = RuleErrorBuilder::message(PhpstanIgnoreRule::MESSAGE)
                        ->file($file)
                        ->line($line)
                        ->identifier(PhpstanIgnoreRule::IDENTIFIER)
                        ->build();
                }
            }
        }

        return $errors;
    }
}
