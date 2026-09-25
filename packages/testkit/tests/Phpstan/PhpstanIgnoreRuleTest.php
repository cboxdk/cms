<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreRule;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 2: no phpstan-ignore comment outside Boundary and Adapter, found in the file tokens
 * and reported after the analysis as non-ignorable errors.
 *
 * @extends RuleTestCase<Rule<CollectedDataNode>>
 */
final class PhpstanIgnoreRuleTest extends RuleTestCase
{
    use ReportedErrors;

    private bool $ignorable = false;

    public function test_it_reports_every_ignore_comment_outside_boundary_and_adapter_with_its_identifier(): void
    {
        // Line 5 is before the namespace, in the global namespace. Line 21 is inside a doc
        // comment. Line 39 is a comment that PHPStan attaches to no node. Lines 30 and 33
        // are in Fixture\Receipts\Adapter and are allowed.
        foreach ($this->gatherAnalyserErrors([self::fixture('Ignores')]) as $error) {
            if ($error->getIdentifier() === PhpstanIgnoreRule::IDENTIFIER) {
                self::assertSame(PhpstanIgnoreRule::MESSAGE, $error->getMessage());
                self::assertSame(self::fixture('Ignores'), $error->getFilePath());
            }
        }

        self::assertSame([
            '5 cboxCms.phpstanIgnore',
            '9 cboxCms.phpstanIgnore',
            '12 cboxCms.phpstanIgnore',  // ignore-line on its own line
            '14 cboxCms.phpstanIgnore',
            '16 cboxCms.phpstanIgnore',  // ignores cboxCms.phpstanIgnore on its own line
            '21 cboxCms.phpstanIgnore',
            '39 cboxCms.phpstanIgnore',  // ignore-line on its own line
        ], $this->reported('Ignores'));
    }

    public function test_the_same_errors_built_as_ignorable_would_be_hidden_by_the_comments_they_report(): void
    {
        // The control for the test above. As ordinary errors, the comments on lines 5, 12,
        // 16 and 39 hide the error about themselves. Only the comments that target another
        // line (9, 21) or another identifier (14) would stay visible.
        $this->ignorable = true;

        self::assertSame([
            '9 cboxCms.phpstanIgnore (ignorable)',
            '14 cboxCms.phpstanIgnore (ignorable)',
            '21 cboxCms.phpstanIgnore (ignorable)',
        ], $this->reported('Ignores'));
    }

    public function test_it_reports_nothing_in_a_file_without_ignore_comments(): void
    {
        $this->analyse([self::fixture('Layers')], []);
    }

    /**
     * @return Rule<CollectedDataNode>
     */
    protected function getRule(): Rule
    {
        if (! $this->ignorable) {
            return new PhpstanIgnoreRule;
        }

        return new IgnorablePhpstanIgnoreRule;
    }

    protected function getCollectors(): array
    {
        return [new PhpstanIgnoreCollector];
    }
}
