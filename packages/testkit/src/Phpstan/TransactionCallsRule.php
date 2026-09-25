<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
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
 * GUARDRAILS 4.1 and PRD 4.2: no transaction calls in the Actions and Jobs layers. The
 * command bus opens the one transaction of a command; savepoints and nested transactions are
 * forbidden, because more than 64 subtransactions slow down every replica.
 *
 * It reports transaction(), beginTransaction(), commit(), rollBack(), savepoint() and
 * createSavepoint() called on a database connection, the connection resolver (the database
 * manager), PDO, or statically on the DB facade. The Arch suite only sees class
 * dependencies; a connection reached through a method call is not a dependency, so this
 * has to be a PHPStan rule. Raw SAVEPOINT statements are SavepointStringsRule.
 *
 * The errors are non-ignorable.
 *
 * @implements Rule<CallLike>
 */
#[Internal]
final readonly class TransactionCallsRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.transaction';

    /** The transaction methods, lower case, since PHP method names are case-insensitive. */
    public const array METHODS = ['transaction', 'begintransaction', 'commit', 'rollback', 'savepoint', 'createsavepoint'];

    /** The receivers that manage transactions, with their subclasses and implementations. */
    public const array RECEIVERS = [
        ConnectionInterface::class,
        ConnectionResolverInterface::class,
        'PDO',
    ];

    /** The facade whose static calls reach a connection. */
    public const string FACADE = DB::class;

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
        if (! TransactionScope::applies($scope)) {
            return [];
        }

        if (! ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)) {
            return [];
        }

        $name = $node->name;

        if (! $name instanceof Identifier || ! in_array($name->toLowerString(), self::METHODS, true)) {
            return [];
        }

        $receiver = $node instanceof StaticCall
            ? $this->facadeIn($node, $scope)
            : $this->connectionIn($scope->getType($node->var));

        if ($receiver === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call to %s::%s() in the %s layer. Actions and jobs never manage transactions: the command bus opens the one transaction of a command, and savepoints and nested transactions are forbidden (GUARDRAILS 4.1, PRD 4.2).',
                $receiver,
                $name->toString(),
                LayerScope::layerOf($scope->getNamespace() ?? ''),
            ))
                ->identifier(self::IDENTIFIER)
                ->nonIgnorable()
                ->build(),
        ];
    }

    /**
     * The first class in the type that is a connection, a connection resolver or PDO.
     */
    private function connectionIn(Type $type): ?string
    {
        foreach ($type->getObjectClassReflections() as $class) {
            foreach (self::RECEIVERS as $receiver) {
                if ($this->reflectionProvider->hasClass($receiver) && $class->is($receiver)) {
                    return $class->getDisplayName();
                }
            }
        }

        return null;
    }

    private function facadeIn(StaticCall $call, Scope $scope): ?string
    {
        if (! $call->class instanceof Name) {
            return $this->connectionIn($scope->getType($call->class));
        }

        $name = $scope->resolveName($call->class);

        if (! $this->reflectionProvider->hasClass($name)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($name);

        return $class->is(self::FACADE) ? $class->getDisplayName() : null;
    }
}
