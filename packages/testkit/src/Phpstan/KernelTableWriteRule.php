<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * PRD 6.5 invariants 1 and 13, 11.12: every write to the kernel's tables goes through a command
 * of the kernel, so no code outside the kernel's own module writes to them by name. The tables are
 * KernelTables::NAMES and their managed partitions.
 *
 * It reports, outside the core module (the namespace Cbox\Cms\Core and the core's migrations) and
 * the testkit's fixture writers (FIXTURE_WRITERS, test-only code that production code never
 * loads):
 * - a write method of the query builder, such as insert(), update(), upsert(), delete() or
 *   truncate(), on a builder whose chain starts at table() or from() with a kernel table, on a
 *   connection, the database manager or the DB facade;
 * - SQL that inserts into, updates, deletes from, truncates, merges into or copies into a kernel
 *   table, given as a constant string to a method of a connection, the database manager, the DB
 *   facade, a query builder or PDO that takes SQL, or to new Illuminate\Database\Query\Expression.
 *
 * A table name held in a variable the chain does not show, or SQL that is not a constant string,
 * is not seen. Every namespace is checked, test code included, and the errors are non-ignorable.
 *
 * @implements Rule<CallLike>
 */
#[Internal]
final readonly class KernelTableWriteRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.kernelTableWrite';

    /** The kernel's own module, which owns the tables and writes them. */
    public const string KERNEL = 'Cbox\Cms\Core';

    /**
     * The one allowed writer outside the kernel: the testkit's fixture writers, which tests use to
     * create actors, credentials, grants, sites, nodes, routes and mounts. No production module
     * uses this namespace; an Arch test in cboxdk/cms holds that.
     */
    public const string FIXTURE_WRITERS = 'Cbox\Cms\Testkit\FixtureWriters';

    /**
     * The query builder's methods that write, lower case.
     *
     * @var list<string>
     */
    public const array WRITE_METHODS = [
        'decrement', 'decrementeach', 'delete', 'increment', 'incrementeach', 'insert', 'insertgetid',
        'insertorignore', 'insertorignoreusing', 'insertusing', 'truncate', 'update', 'updatefrom',
        'updateorinsert', 'upsert',
    ];

    /**
     * The builder methods that name the table a chain works on, lower case.
     *
     * @var list<string>
     */
    public const array TABLE_METHODS = ['from', 'table'];

    /**
     * The statements that write a table, and the table after them.
     */
    public const string WRITE_SQL = '/\b(?:insert\s+into|update(?:\s+only)?|delete\s+from(?:\s+only)?|merge\s+into|copy|truncate(?:\s+table)?(?:\s+only)?)\s+((?:"?[a-z_][a-z0-9_$]*"?\s*\.\s*)?"?[a-z_][a-z0-9_$]*"?(?:\s*,\s*(?:"?[a-z_][a-z0-9_$]*"?\s*\.\s*)?"?[a-z_][a-z0-9_$]*"?)*)/i';

    /** The core module's directory, packages/core, where files in the global namespace, its migrations, belong to the kernel. */
    private string $coreDirectory;

    public function __construct(private ReflectionProvider $reflectionProvider, ?string $coreDirectory = null)
    {
        $this->coreDirectory = $coreDirectory ?? dirname(__DIR__, 3).'/core';
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isKernel($scope)) {
            return [];
        }

        $tables = match (true) {
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => [...$this->builderWrite($node, $scope), ...$this->sqlWrite($node, $scope)],
            $node instanceof StaticCall => $this->sqlWrite($node, $scope),
            $node instanceof New_ => $this->expression($node, $scope),
            default => [],
        };

        return array_map(static fn (string $table): IdentifierRuleError => RuleErrorBuilder::message(sprintf(
            'Write to the kernel table %s outside the kernel. Only the kernel writes its tables, through its commands (PRD 6.5 invariants 1 and 13, 11.12); a test creates what it needs through the testkit\'s fixture writers in %s.',
            $table,
            self::FIXTURE_WRITERS,
        ))
            ->identifier(self::IDENTIFIER)
            ->nonIgnorable()
            ->build(), array_values(array_unique($tables)));
    }

    private function isKernel(Scope $scope): bool
    {
        $namespace = $scope->getNamespace() ?? '';

        if ($namespace === '') {
            $file = realpath($scope->getFile());
            $core = realpath($this->coreDirectory);

            return $file !== false && $core !== false && str_starts_with($file, $core.'/');
        }

        return $this->below($namespace, self::KERNEL) || $this->below($namespace, self::FIXTURE_WRITERS);
    }

    private function below(string $namespace, string $root): bool
    {
        return $namespace === $root || str_starts_with($namespace, $root.'\\');
    }

    /**
     * The kernel tables a query builder write reaches: the table its chain names. The write counts
     * when it is called on a query builder, or when its chain starts at a connection, the database
     * manager, the DB facade or a query builder, as in DB::table('nodes')->where(...)->update(...).
     *
     * @return list<string>
     */
    private function builderWrite(MethodCall|NullsafeMethodCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! in_array($node->name->toLowerString(), self::WRITE_METHODS, true)) {
            return [];
        }

        $tables = null;
        $link = $node->var;

        while ($link instanceof MethodCall || $link instanceof NullsafeMethodCall || $link instanceof StaticCall) {
            if ($tables === null && $link->name instanceof Identifier && in_array($link->name->toLowerString(), self::TABLE_METHODS, true) && ! $link->isFirstClassCallable()) {
                $first = $link->getArgs()[0] ?? null;
                $tables = $first === null ? [] : $this->tablesNamed($scope->getType($first->value));
            }

            if ($link instanceof StaticCall) {
                break;
            }

            $link = $link->var;
        }

        if ($tables === null || $tables === [] || ! $this->is($scope->getType($node->var), QueryBuilder::class) && ! $this->isDatabase($link, $scope)) {
            return [];
        }

        return $tables;
    }

    /**
     * Whether the root of a chain is a connection, the database manager, the DB facade or a query
     * builder.
     */
    private function isDatabase(Expr $root, Scope $scope): bool
    {
        if ($root instanceof StaticCall) {
            if (! $root->class instanceof Name) {
                return $this->isDatabaseType($scope->getType($root->class));
            }

            $class = $scope->resolveName($root->class);

            return $this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->is(DB::class);
        }

        return $this->isDatabaseType($scope->getType($root));
    }

    private function isDatabaseType(Type $type): bool
    {
        return $this->is($type, ConnectionInterface::class) || $this->is($type, ConnectionResolverInterface::class) || $this->is($type, QueryBuilder::class);
    }

    /**
     * @return list<string>
     */
    private function tablesNamed(Type $type): array
    {
        $tables = [];

        foreach ($type->getConstantStrings() as $string) {
            $name = preg_split('/\s+as\s+|\s+/i', trim($string->getValue()))[0] ?? '';
            $table = KernelTables::of($name);

            if ($table !== null) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * The kernel tables that SQL given to a method that takes SQL writes.
     *
     * @return list<string>
     */
    private function sqlWrite(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || $node->isFirstClassCallable() || ! $this->takesSql($node, $node->name->toLowerString(), $scope)) {
            return [];
        }

        $first = $node->getArgs()[0] ?? null;

        return $first === null ? [] : $this->writtenBy($first->value, $scope);
    }

    private function takesSql(MethodCall|NullsafeMethodCall|StaticCall $node, string $method, Scope $scope): bool
    {
        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $class = $scope->resolveName($node->class);

            return in_array($method, RawSqlRule::CONNECTION_METHODS, true)
                && $this->reflectionProvider->hasClass($class)
                && $this->reflectionProvider->getClass($class)->is(DB::class);
        }

        $receiver = $scope->getType($node instanceof StaticCall ? $node->class : $node->var);

        return (in_array($method, RawSqlRule::CONNECTION_METHODS, true) && ($this->is($receiver, ConnectionInterface::class) || $this->is($receiver, ConnectionResolverInterface::class)))
            || (in_array($method, RawSqlRule::BUILDER_METHODS, true) && $this->is($receiver, QueryBuilder::class))
            || (in_array($method, RawSqlRule::PDO_METHODS, true) && $this->is($receiver, 'PDO'));
    }

    /**
     * @return list<string>
     */
    private function expression(New_ $node, Scope $scope): array
    {
        if (! $node->class instanceof Name || $node->isFirstClassCallable()) {
            return [];
        }

        $class = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($class) || ! $this->reflectionProvider->getClass($class)->is(Expression::class)) {
            return [];
        }

        $first = $node->getArgs()[0] ?? null;

        return $first === null ? [] : $this->writtenBy($first->value, $scope);
    }

    /**
     * @return list<string>
     */
    private function writtenBy(Expr $sql, Scope $scope): array
    {
        $tables = [];

        foreach ($scope->getType($sql)->getConstantStrings() as $string) {
            $tables = [...$tables, ...self::tablesWrittenBy($string->getValue())];
        }

        return $tables;
    }

    /**
     * The kernel tables a statement inserts into, updates, deletes from, truncates, merges into or
     * copies into.
     *
     * @return list<string>
     */
    public static function tablesWrittenBy(string $sql): array
    {
        preg_match_all(self::WRITE_SQL, $sql, $matches);
        $tables = [];

        foreach ($matches[1] as $list) {
            foreach (explode(',', $list) as $name) {
                $table = KernelTables::of(preg_replace('/\s+/', '', $name) ?? $name);

                if ($table !== null) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    private function is(Type $type, string $class): bool
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return false;
        }

        return array_any($type->getObjectClassReflections(), fn (ClassReflection $reflection): bool => $reflection->is($class));
    }
}
