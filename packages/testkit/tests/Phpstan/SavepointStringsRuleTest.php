<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\SavepointStringsRule;
use PhpParser\Node\Scalar;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 3, GUARDRAILS 4.1 and PRD 4.2: no SAVEPOINT statements in Actions and Jobs, also not
 * as raw SQL.
 *
 * @extends RuleTestCase<Rule<Scalar>>
 */
final class SavepointStringsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_savepoint_strings_in_actions_and_jobs_but_not_in_an_adapter_or_tests(): void
    {
        // Not reported: 'select 1' (line 64), the Adapter (line 98) and the tests (line 115).
        self::assertSame([
            '37 cboxCms.savepoint',  // 'SAVEPOINT chunk_1'
            '52 cboxCms.savepoint',  // a heredoc with "release savepoint {$name}"
            '81 cboxCms.savepoint',  // 'SAVEPOINT prune' in Jobs
        ], $this->reported('Transactions'));
    }

    public function test_the_message_names_the_layer(): void
    {
        $this->analyse([self::fixture('Transactions')], [
            [sprintf(SavepointStringsRule::MESSAGE, 'Actions'), 37],
            [sprintf(SavepointStringsRule::MESSAGE, 'Actions'), 52],
            [sprintf(SavepointStringsRule::MESSAGE, 'Jobs'), 81],
        ]);
    }

    /**
     * @return Rule<Scalar>
     */
    protected function getRule(): Rule
    {
        return new SavepointStringsRule;
    }
}
