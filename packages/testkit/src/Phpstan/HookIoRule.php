<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Psr\Container\ContainerInterface;

/**
 * PRD 6.3 and 11.12: a hook is deterministic and does no IO. A class that implements a hook
 * interface (AuthorizeHook, TransformHook or ValidateHook, Phase::hookInterface()) may not reach
 * the network, the filesystem, another program, the database, the cache or Redis.
 *
 * It reports, in such a class and the traits it uses:
 * - a call of a function in EgressNames or DATABASE_FUNCTIONS, or with a prefix from either,
 *   also as a first-class callable or named by a string argument;
 * - a use of a class in EgressNames or DATABASE_CLASSES, or of a class that extends or
 *   implements one, such as an Eloquent model: `new`, a static call, a constant (`::class`
 *   included), a static property, `instanceof`, a method call on it, and a parameter, return or
 *   property type, which is how the container would hand one in;
 * - a call of a method in EgressNames::METHODS, such as SplFileInfo::openFile();
 * - a container id in EgressNames or DATABASE_SERVICE_IDS given to app(), resolve() or a
 *   container's make(), makeWith(), get() or offsetGet(), or read as `$container['db']`, and a
 *   class name from the lists given to any call.
 *
 * First-class callables such as file_get_contents(...) reach it through FunctionCallablesRule,
 * MethodCallablesRule and StaticMethodCallablesRule. It looks at the hook class itself, not at the classes it calls: a hook that needs data asks the
 * PlanView it is given. Every hook is checked, test code included, and the errors are
 * non-ignorable.
 *
 * @implements Rule<Node>
 */
#[Internal]
final readonly class HookIoRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.hookIo';

    /**
     * The database, the cache and Redis, besides what EgressNames lists. A name that ends in a
     * backslash is a namespace.
     *
     * @var list<string>
     */
    public const array DATABASE_CLASSES = [
        'Doctrine\DBAL\\',
        'Illuminate\Cache\\',
        'Illuminate\Contracts\Cache\\',
        'Illuminate\Contracts\Database\\',
        'Illuminate\Contracts\Redis\\',
        'Illuminate\Database\\',
        'Illuminate\Redis\\',
        Cache::class,
        DB::class,
        Redis::class,
        Schema::class,
        'Memcached',
        'mysqli',
        'PDO',
        'PDOStatement',
        'Predis\\',
        'Redis',
        'RedisCluster',
        'SQLite3',
    ];

    /**
     * Functions that reach the database or a cache: Laravel's cache() helper.
     *
     * @var list<string>
     */
    public const array DATABASE_FUNCTIONS = ['cache'];

    /**
     * Families of functions that reach a database or a shared cache.
     *
     * @var list<string>
     */
    public const array DATABASE_FUNCTION_PREFIXES = ['apcu_', 'mysqli_', 'pg_', 'sqlite_'];

    /**
     * The container ids of the database, the cache and Redis.
     *
     * @var list<string>
     */
    public const array DATABASE_SERVICE_IDS = [
        'cache',
        'cache.store',
        'db',
        'db.connection',
        'db.factory',
        'db.schema',
        'redis',
        'redis.connection',
    ];

    /**
     * The methods of a container that resolve an id, lower case.
     *
     * @var list<string>
     */
    public const array RESOLVING_METHODS = ['get', 'make', 'makewith', 'offsetget'];

    /**
     * The functions that resolve an id from the container.
     *
     * @var list<string>
     */
    public const array RESOLVING_FUNCTIONS = ['app', 'resolve'];

    /**
     * The container interfaces whose resolving methods are checked.
     *
     * @var list<string>
     */
    public const array CONTAINERS = [Container::class, ContainerInterface::class];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (! $class instanceof ClassReflection || ! $this->isHook($class)) {
            return [];
        }

        $uses = match (true) {
            $node instanceof FuncCall => $this->functionCall($node, $scope),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->methodCall($node, $scope),
            $node instanceof StaticCall => [...$this->classExpression($node->class, $scope), ...$this->methodName($node->name), ...$this->arguments($node, $scope)],
            $node instanceof New_ => [...$this->classExpression($node->class, $scope), ...$this->arguments($node, $scope)],
            $node instanceof ClassConstFetch, $node instanceof StaticPropertyFetch, $node instanceof Instanceof_ => $this->classExpression($node->class, $scope),
            $node instanceof ArrayDimFetch => $this->containerOffset($node, $scope),
            $node instanceof ClassMethod => $this->signature($node, $scope),
            $node instanceof Property => $this->declaredType($node->type, $scope),
            default => [],
        };

        return array_map(fn (string $use): IdentifierRuleError => RuleErrorBuilder::message(sprintf(
            'Hook %s uses %s. A hook is deterministic and does no IO: no network, filesystem, process, database, cache or Redis (PRD 6.3, 11.12). It reads what it needs from the PlanView it is given.',
            $class->getDisplayName(),
            $use,
        ))
            ->identifier(self::IDENTIFIER)
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build(), array_values(array_unique($uses)));
    }

    private function isHook(ClassReflection $class): bool
    {
        return array_any(Phase::cases(), static fn (Phase $phase): bool => $class->implementsInterface($phase->hookInterface()));
    }

    /**
     * @return list<string>
     */
    private function functionCall(FuncCall $node, Scope $scope): array
    {
        $uses = $this->arguments($node, $scope);

        if (! $node->name instanceof Name) {
            foreach ($scope->getType($node->name)->getConstantStrings() as $name) {
                if ($this->forbiddenFunction($name->getValue())) {
                    $uses[] = sprintf('the function %s()', $name->getValue());
                }
            }

            return $uses;
        }

        $name = $this->reflectionProvider->hasFunction($node->name, $scope)
            ? $this->reflectionProvider->getFunction($node->name, $scope)->getName()
            : $node->name->toString();

        if ($this->forbiddenFunction($name)) {
            $uses[] = sprintf('the function %s()', $name);
        }

        if (in_array(strtolower($name), self::RESOLVING_FUNCTIONS, true)) {
            return [...$uses, ...$this->serviceIds($node, $scope)];
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function methodCall(MethodCall|NullsafeMethodCall $node, Scope $scope): array
    {
        $receiver = $scope->getType($node->var);
        $uses = [...$this->typeUses($receiver), ...$this->methodName($node->name), ...$this->arguments($node, $scope)];

        if ($node->name instanceof Identifier
            && in_array($node->name->toLowerString(), self::RESOLVING_METHODS, true)
            && $this->isContainer($receiver)) {
            return [...$uses, ...$this->serviceIds($node, $scope)];
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function containerOffset(ArrayDimFetch $node, Scope $scope): array
    {
        if (! $node->dim instanceof Expr || ! $this->isContainer($scope->getType($node->var))) {
            return [];
        }

        return $this->serviceIdsOf($scope->getType($node->dim));
    }

    /**
     * @return list<string>
     */
    private function methodName(Node $name): array
    {
        return $name instanceof Identifier && in_array($name->toLowerString(), EgressNames::METHODS, true)
            ? [sprintf('the method %s()', $name->toString())]
            : [];
    }

    /**
     * @return list<string>
     */
    private function classExpression(Node $class, Scope $scope): array
    {
        if ($class instanceof Name) {
            $name = $scope->resolveName($class);

            return $this->forbiddenClass($name) ? [sprintf('the class %s', $name)] : [];
        }

        return $class instanceof Expr ? $this->typeUses($scope->getType($class)) : [];
    }

    /**
     * The classes of the lists that a method's parameters, promoted properties included, or its
     * return type name.
     *
     * @return list<string>
     */
    private function signature(ClassMethod $node, Scope $scope): array
    {
        $uses = $this->declaredType($node->returnType, $scope);

        foreach ($node->params as $parameter) {
            $uses = [...$uses, ...$this->declaredType($parameter->type, $scope)];
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function declaredType(Identifier|Name|ComplexType|null $type, Scope $scope): array
    {
        return $type === null ? [] : $this->typeUses($scope->getFunctionType($type, false, false));
    }

    /**
     * Class names from the lists given as string arguments of a call, such as a class the
     * container resolves, and function names from the lists given where the call takes a
     * callable, such as array_map('file_get_contents', ...). A function name elsewhere is a word.
     *
     * @return list<string>
     */
    private function arguments(FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $parameters = $this->parameters($node, $scope);
        $uses = [];

        foreach ($node->getArgs() as $position => $argument) {
            $callable = ($parameters[$position] ?? null) !== null
                && TypeCombinator::removeNull($parameters[$position])->isCallable()->yes();

            foreach ($scope->getType($argument->value)->getConstantStrings() as $string) {
                $value = ltrim($string->getValue(), '\\');

                if (in_array($value, [...EgressNames::SERVICE_IDS, ...self::DATABASE_SERVICE_IDS], true)) {
                    continue;
                }
                $class = explode('::', $value, 2)[0];

                if ($this->forbiddenClass($class)) {
                    $uses[] = sprintf('the class %s', $class);
                } elseif ($callable && $this->forbiddenFunction($value)) {
                    $uses[] = sprintf('the function %s()', $value);
                }
            }
        }

        return $uses;
    }

    /**
     * The types of the parameters a call's arguments go to, by position, where PHPStan knows
     * the function or method.
     *
     * @return array<int, Type>
     */
    private function parameters(FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $node, Scope $scope): array
    {
        $variants = match (true) {
            $node instanceof FuncCall => $node->name instanceof Name && $this->reflectionProvider->hasFunction($node->name, $scope)
                ? $this->reflectionProvider->getFunction($node->name, $scope)->getVariants()
                : [],
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $node->name instanceof Identifier
                ? ($scope->getMethodReflection($scope->getType($node->var), $node->name->toString())?->getVariants() ?? [])
                : [],
            $node instanceof StaticCall => $node->class instanceof Name && $node->name instanceof Identifier
                ? ($scope->getMethodReflection($scope->resolveTypeByName($node->class), $node->name->toString())?->getVariants() ?? [])
                : [],
            default => [],
        };

        if ($variants === []) {
            return [];
        }

        $types = [];

        foreach (ParametersAcceptorSelector::selectFromArgs($scope, $node->getArgs(), $variants)->getParameters() as $position => $parameter) {
            $types[$position] = $parameter->getType();
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function serviceIds(FuncCall|MethodCall|NullsafeMethodCall $node, Scope $scope): array
    {
        $first = $node->getArgs()[0] ?? null;

        return $first instanceof Arg ? $this->serviceIdsOf($scope->getType($first->value)) : [];
    }

    /**
     * @return list<string>
     */
    private function serviceIdsOf(Type $type): array
    {
        $uses = [];

        foreach ($type->getConstantStrings() as $string) {
            if (in_array($string->getValue(), [...EgressNames::SERVICE_IDS, ...self::DATABASE_SERVICE_IDS], true)) {
                $uses[] = sprintf("the container id '%s'", $string->getValue());
            }
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function typeUses(Type $type): array
    {
        $uses = [];

        foreach ($type->getObjectClassReflections() as $class) {
            if ($this->forbiddenReflection($class)) {
                $uses[] = sprintf('the class %s', $class->getName());
            }
        }

        return $uses;
    }

    private function isContainer(Type $type): bool
    {
        return array_any(
            $type->getObjectClassReflections(),
            fn (ClassReflection $class): bool => array_any(self::CONTAINERS, fn (string $container): bool => $this->reflectionProvider->hasClass($container) && $class->is($container)),
        );
    }

    private function forbiddenFunction(string $function): bool
    {
        $lower = strtolower(ltrim($function, '\\'));

        return in_array($lower, [...EgressNames::FUNCTIONS, ...self::DATABASE_FUNCTIONS], true)
            || array_any([...EgressNames::FUNCTION_PREFIXES, ...self::DATABASE_FUNCTION_PREFIXES], static fn (string $prefix): bool => str_starts_with($lower, $prefix));
    }

    /**
     * A class is forbidden when it, a parent or an interface is in the lists.
     */
    private function forbiddenClass(string $name): bool
    {
        if ($this->listed($name)) {
            return true;
        }

        return $this->reflectionProvider->hasClass($name) && $this->forbiddenReflection($this->reflectionProvider->getClass($name));
    }

    private function forbiddenReflection(ClassReflection $class): bool
    {
        if ($this->listed($class->getName())) {
            return true;
        }

        return array_any([...$class->getParents(), ...array_values($class->getInterfaces())], fn (ClassReflection $ancestor): bool => $this->listed($ancestor->getName()));
    }

    private function listed(string $class): bool
    {
        $lower = strtolower(ltrim($class, '\\'));

        foreach ([...EgressNames::CLASSES, ...self::DATABASE_CLASSES] as $entry) {
            $entryLower = strtolower($entry);
            $matches = str_ends_with($entry, '\\')
                ? str_starts_with($lower, $entryLower)
                : $lower === $entryLower;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
