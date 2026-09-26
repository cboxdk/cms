<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\RawSqlRule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 6, GUARDRAILS 6: raw SQL only in migrations, Infrastructure and Adapter.
 *
 * @extends RuleTestCase<Rule<CallLike>>
 */
final class RawSqlRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_raw_sql_outside_infrastructure_adapter_and_tests(): void
    {
        // Not reported: the query builder's own methods (lines 58 to 61), Infrastructure (lines 116
        // and 118), the Adapter (lines 131 and 132) and the tests (line 144).
        self::assertSame([
            '24 cboxCms.rawSql',   // $connection->select()
            '25 cboxCms.rawSql',   // $connection->statement()
            '26 cboxCms.rawSql',   // $connection->scalar()
            '31 cboxCms.rawSql',   // DB::select()
            '32 cboxCms.rawSql',   // DB::raw()
            '37 cboxCms.rawSql',   // whereRaw()
            '37 cboxCms.rawSql',   // orderByRaw()
            '37 cboxCms.rawSql',   // selectRaw()
            '42 cboxCms.rawSql',   // Model::whereRaw()
            '47 cboxCms.rawSql',   // PDO::query()
            '52 cboxCms.rawSql',   // new Expression
            '73 cboxCms.rawSql',   // whereRaw() in an action
            '87 cboxCms.rawSql',   // $connection->insert() in a Boundary
            '101 cboxCms.rawSql',  // DatabaseManager::statement() outside a layer
        ], $this->reported('RawSql'));
    }

    public function test_the_message_names_the_call_the_place_and_the_rule(): void
    {
        $this->analyse([self::fixture('RawSql')], [
            [$this->message('Illuminate\Database\ConnectionInterface::select()', 'the Domain layer'), 24],
            [$this->message('Illuminate\Database\ConnectionInterface::statement()', 'the Domain layer'), 25],
            [$this->message('Illuminate\Database\ConnectionInterface::scalar()', 'the Domain layer'), 26],
            [$this->message(DB::class.'::select()', 'the Domain layer'), 31],
            [$this->message(DB::class.'::raw()', 'the Domain layer'), 32],
            [$this->message(Builder::class.'::whereRaw()', 'the Domain layer'), 37],
            [$this->message(Builder::class.'::orderByRaw()', 'the Domain layer'), 37],
            [$this->message(Builder::class.'::selectRaw()', 'the Domain layer'), 37],
            [$this->message('Fixture\RawSql\Domain\Article::whereRaw()', 'the Domain layer'), 42],
            [$this->message('PDO::query()', 'the Domain layer'), 47],
            [$this->message('new Illuminate\Database\Query\Expression', 'the Domain layer'), 52],
            [$this->message(Builder::class.'::whereRaw()', 'the Actions layer'), 73],
            [$this->message('Illuminate\Database\ConnectionInterface::insert()', 'the Boundary layer'), 87],
            [$this->message(DatabaseManager::class.'::statement()', 'code outside a layer'), 101],
        ]);
    }

    private function message(string $call, string $place): string
    {
        return sprintf(
            'Raw SQL through %s in %s. SQL goes through the query builder; raw SQL belongs in migrations and in the Infrastructure and Adapter layers, tested against real Postgres (GUARDRAILS 6).',
            $call,
            $place,
        );
    }

    /**
     * @return Rule<CallLike>
     */
    protected function getRule(): Rule
    {
        return new RawSqlRule(self::createReflectionProvider());
    }
}
