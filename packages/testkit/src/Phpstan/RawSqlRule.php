<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * GUARDRAILS 6: SQL goes through the query builder. Raw SQL belongs in migrations and in the
 * Infrastructure and Adapter layers, which are tested against real Postgres.
 *
 * It reports, outside those layers:
 * - the methods of a database connection, the connection resolver (the database manager) and
 *   the DB facade that take an SQL string, such as select(), statement() and raw();
 * - the raw methods of the query builder, the Eloquent builder, relations and static calls on
 *   a model, such as whereRaw() and selectRaw();
 * - query(), exec() and prepare() on PDO;
 * - new Illuminate\Database\Query\Expression.
 *
 * Test code is not checked, and neither is the global namespace, where migrations and Pest files
 * live. The errors are non-ignorable.
 *
 * @implements Rule<CallLike>
 */
#[Internal]
final readonly class RawSqlRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.rawSql';

    /**
     * The layers where raw SQL is allowed.
     *
     * @var list<string>
     */
    public const array LAYERS = ['Infrastructure', 'Adapter'];

    /**
     * Methods of a connection, the resolver and the DB facade that take SQL, lower case.
     *
     * @var list<string>
     */
    public const array CONNECTION_METHODS = [
        'select', 'selectone', 'selectfromwriteconnection', 'selectresultsets', 'scalar', 'cursor',
        'insert', 'update', 'delete', 'statement', 'affectingstatement', 'unprepared', 'raw',
    ];

    /**
     * The raw methods of the query builders, lower case.
     *
     * @var list<string>
     */
    public const array BUILDER_METHODS = [
        'raw', 'selectraw', 'fromraw', 'whereraw', 'orwhereraw', 'havingraw', 'orhavingraw',
        'orderbyraw', 'groupbyraw',
    ];

    /**
     * The methods of PDO that take SQL, lower case.
     *
     * @var list<string>
     */
    public const array PDO_METHODS = ['query', 'exec', 'prepare'];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';

        if (LayerScope::isTestCode($namespace) || in_array(LayerScope::layerOf($namespace), self::LAYERS, true)) {
            return [];
        }

        $call = match (true) {
            $node instanceof New_ => $this->expressionIn($node, $scope),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->methodCall($node, $scope),
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            default => null,
        };

        if ($call === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw SQL through %s in %s. SQL goes through the query builder; raw SQL belongs in migrations and in the Infrastructure and Adapter layers, tested against real Postgres (GUARDRAILS 6).',
                $call,
                ($layer = LayerScope::layerOf($namespace)) === null ? 'code outside a layer' : sprintf('the %s layer', $layer),
            ))
                ->identifier(self::IDENTIFIER)
                ->nonIgnorable()
                ->build(),
        ];
    }

    private function expressionIn(New_ $node, Scope $scope): ?string
    {
        if (! $node->class instanceof Name) {
            return null;
        }

        $name = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($name) || ! $this->reflectionProvider->getClass($name)->is(Expression::class)) {
            return null;
        }

        return sprintf('new %s', $this->reflectionProvider->getClass($name)->getDisplayName());
    }

    private function methodCall(MethodCall|NullsafeMethodCall $node, Scope $scope): ?string
    {
        $method = $this->methodName($node->name);

        if ($method === null) {
            return null;
        }

        $receiver = $this->receiverFor($scope->getType($node->var), strtolower($method));

        return $receiver === null ? null : sprintf('%s::%s()', $receiver, $method);
    }

    private function staticCall(StaticCall $node, Scope $scope): ?string
    {
        $method = $this->methodName($node->name);

        if ($method === null) {
            return null;
        }

        if (! $node->class instanceof Name) {
            $receiver = $this->receiverFor($scope->getType($node->class), strtolower($method));

            return $receiver === null ? null : sprintf('%s::%s()', $receiver, $method);
        }

        $name = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($name)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($name);
        $lower = strtolower($method);

        $raw = ($class->is(DB::class) && in_array($lower, self::CONNECTION_METHODS, true))
            || ($class->is(Model::class) && in_array($lower, self::BUILDER_METHODS, true));

        return $raw ? sprintf('%s::%s()', $class->getDisplayName(), $method) : null;
    }

    /**
     * The first class in the type whose method of this name takes raw SQL.
     */
    private function receiverFor(Type $type, string $method): ?string
    {
        $receivers = [
            ConnectionInterface::class => self::CONNECTION_METHODS,
            ConnectionResolverInterface::class => self::CONNECTION_METHODS,
            QueryBuilder::class => self::BUILDER_METHODS,
            EloquentBuilder::class => self::BUILDER_METHODS,
            Relation::class => self::BUILDER_METHODS,
            'PDO' => self::PDO_METHODS,
        ];

        foreach ($type->getObjectClassReflections() as $class) {
            foreach ($receivers as $receiver => $methods) {
                if (in_array($method, $methods, true) && $this->reflectionProvider->hasClass($receiver) && $class->is($receiver)) {
                    return $class->getDisplayName();
                }
            }
        }

        return null;
    }

    private function methodName(Node $name): ?string
    {
        return $name instanceof Identifier ? $name->toString() : null;
    }
}
