<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * GUARDRAILS 4.1 and PRD 4.2: no savepoints in the Actions and Jobs layers, also not as raw
 * SQL. It reports every string literal, heredoc and nowdoc there that contains the SQL
 * keyword SAVEPOINT, in any case. A savepoint cannot be created without that keyword; ROLLBACK
 * TO and RELEASE only act on one that exists. Transaction calls are TransactionCallsRule.
 *
 * The errors are non-ignorable.
 *
 * @implements Rule<Scalar>
 */
#[Internal]
final class SavepointStringsRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.savepoint';

    public const string MESSAGE = 'A SAVEPOINT statement in the %s layer. Actions and jobs never use savepoints or nested transactions (GUARDRAILS 4.1, PRD 4.2): a chunk is all or nothing, so validate the lines first, take out the failed ones and repeat the chunk.';

    public function getNodeType(): string
    {
        return Scalar::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! TransactionScope::applies($scope) || preg_match('/\bsavepoint\b/i', $this->textOf($node)) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(self::MESSAGE, LayerScope::layerOf($scope->getNamespace() ?? '')))
                ->identifier(self::IDENTIFIER)
                ->nonIgnorable()
                ->build(),
        ];
    }

    /**
     * The literal text of a string, or of the literal parts of an interpolated string.
     */
    private function textOf(Scalar $node): string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        if (! $node instanceof InterpolatedString) {
            return '';
        }

        $text = '';

        foreach ($node->parts as $part) {
            $text .= $part instanceof InterpolatedStringPart ? $part->value : ' ';
        }

        return $text;
    }
}
