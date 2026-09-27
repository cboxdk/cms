<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Phpstan\Boundary\InternalUseRecords;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule 5 of the testkit configuration (GUARDRAILS 2.3): reports each use of an #[Internal]
 * symbol outside Cbox\Cms, which InternalUseIgnoreErrorExtension took out of the analysis of its
 * file, and which only an explicit ignore can hide.
 *
 * A use on a line that an ignore comment names cboxCms.internalUse on is reported as an ordinary
 * error: PHPStan matches it against that comment, so the comment hides it and counts as used.
 * Each naming of the identifier waives one use of the line. Every other use is non-ignorable,
 * so neither the ignore-line and ignore-next-line forms, an ignore comment for another
 * identifier nor an ignoreErrors entry hides it. An ignore comment outside Boundary and Adapter
 * is reported by cboxCms.phpstanIgnore as well.
 *
 * @implements Rule<CollectedDataNode>
 */
#[Internal]
final class InternalUseRule implements Rule
{
    /** The metadata key on this rule's errors, so InternalUseIgnoreErrorExtension passes them on. */
    public const string REPORTED = 'cboxCms.internalUseReported';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        /** @var array<string, array<int, int>> $waived the waivers, by source file and line */
        $waived = [];
        $sites = [];

        foreach ($node->get(InternalUseCollector::class) as $file => $collected) {
            foreach ($collected as $records) {
                foreach ($records as $record) {
                    $decoded = InternalUseRecords::decode($file, $record);

                    if ($decoded instanceof InternalUseWaiver) {
                        $waived[$decoded->file][$decoded->line] = $decoded->count;
                    } else {
                        $sites[] = $decoded;
                    }
                }
            }
        }

        // PHPStan keeps the ignore comments of a trait apart for each class that uses it, so
        // the waivers of a line are counted for each file PHPStan reports the line under.
        /** @var array<string, int> $used */
        $used = [];
        $errors = [];

        foreach ($sites as $site) {
            $reportedAs = $site->file."\n".$site->description."\n".$site->line;
            $used[$reportedAs] = ($used[$reportedAs] ?? 0) + 1;
            $error = RuleErrorBuilder::message($site->message)
                ->file($site->file, $site->description)
                ->line($site->line)
                ->identifier(InternalUse::IDENTIFIER)
                ->metadata([self::REPORTED => true]);

            if ($used[$reportedAs] > ($waived[$site->source][$site->line] ?? 0)) {
                $error = $error->nonIgnorable();
            }

            $errors[] = $error->build();
        }

        return $errors;
    }
}
