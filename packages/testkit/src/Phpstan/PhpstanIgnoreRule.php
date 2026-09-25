<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule 2 of GUARDRAILS 2.2 and gate 3: no phpstan-ignore annotation, in any form, outside
 * Boundary and Adapter. This applies to tests as well.
 *
 * A node rule can be silenced by the very comment it reports: a trailing ignore comment
 * hides every error on its own line. This rule therefore reads the tokens through
 * PhpstanIgnoreCollector, reports after the analysis, and builds non-ignorable errors, which
 * no comment and no ignoreErrors entry can hide. The token scan in the Arch suite is the
 * backstop for the monorepo.
 *
 * @implements Rule<CollectedDataNode>
 */
#[Internal]
final class PhpstanIgnoreRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.phpstanIgnore';

    public const string MESSAGE = 'A phpstan-ignore comment is only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2). Fix the error, verify the package contract, or add a tested stub or extension; code that needs a suppression belongs in an Adapter.';

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
                    $errors[] = RuleErrorBuilder::message(self::MESSAGE)
                        ->file($file)
                        ->line($line)
                        ->identifier(self::IDENTIFIER)
                        ->nonIgnorable()
                        ->build();
                }
            }
        }

        return $errors;
    }
}
