<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TransactionCallsRule;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 3, GUARDRAILS 4.1 and PRD 4.2: no transaction calls in Actions and Jobs.
 *
 * @extends RuleTestCase<Rule<CallLike>>
 */
final class TransactionCallsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_transaction_calls_in_actions_and_jobs_but_not_in_an_adapter_or_tests(): void
    {
        // Not reported: Changeset::commit() in the domain (line 59), select() (line 64), the
        // Adapter (lines 96 and 97) and the tests (lines 113 and 114).
        self::assertSame([
            '27 cboxCms.transaction',  // DB::transaction()
            '32 cboxCms.transaction',  // $connection->beginTransaction()
            '42 cboxCms.transaction',  // DB::connection()->rollBack()
            '47 cboxCms.transaction',  // PDO::commit()
            '79 cboxCms.transaction',  // DB::transaction() in Jobs
            '80 cboxCms.transaction',  // $connection->beginTransaction() in Jobs
        ], $this->reported('Transactions'));
    }

    public function test_the_message_names_the_call_the_layer_and_the_reason(): void
    {
        $this->analyse([self::fixture('Transactions')], [
            [$this->message(DB::class, 'transaction', 'Actions'), 27],
            [$this->message(ConnectionInterface::class, 'beginTransaction', 'Actions'), 32],
            [$this->message(Connection::class, 'rollBack', 'Actions'), 42],
            [$this->message('PDO', 'commit', 'Actions'), 47],
            [$this->message(DB::class, 'transaction', 'Jobs'), 79],
            [$this->message(ConnectionInterface::class, 'beginTransaction', 'Jobs'), 80],
        ]);
    }

    private function message(string $receiver, string $method, string $layer): string
    {
        return sprintf(
            'Call to %s::%s() in the %s layer. Actions and jobs never manage transactions: the command bus opens the one transaction of a command, and savepoints and nested transactions are forbidden (GUARDRAILS 4.1, PRD 4.2).',
            $receiver,
            $method,
            $layer,
        );
    }

    /**
     * @return Rule<CallLike>
     */
    protected function getRule(): Rule
    {
        return new TransactionCallsRule(self::createReflectionProvider());
    }
}
