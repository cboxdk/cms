<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Ramsey\Uuid\UuidFactoryInterface as RamseyUuidFactory;
use Symfony\Component\Uid\Factory\RandomBasedUuidFactory;
use Symfony\Component\Uid\Factory\TimeBasedUuidFactory;
use Symfony\Component\Uid\Factory\UlidFactory;
use Symfony\Component\Uid\Factory\UuidFactory as SymfonyUuidFactory;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use Symfony\Component\Uid\UuidV1;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV6;
use Symfony\Component\Uid\UuidV7;

/**
 * Finds the expressions that make a new random or time-based UUID or ULID, for
 * UuidCreationRule (GUARDRAILS 2.3, PRD 5.3).
 *
 * - Laravel's Str::uuid(), Str::uuid7(), Str::orderedUuid() and Str::ulid(), and the Eloquent
 *   traits HasUuids, HasVersion4Uuids and HasUlids, which make an id for every new model;
 * - ramsey/uuid's Uuid::uuid1(), uuid2(), uuid4(), uuid6() and uuid7(), the same methods on a
 *   UuidFactoryInterface, and the functions Ramsey\Uuid\v1(), v2(), v4(), v6() and v7();
 * - symfony/uid's Uuid::v1(), v4(), v6() and v7(), UuidV1::generate(), UuidV6::generate(),
 *   UuidV7::generate() and Ulid::generate(), new UuidV1(), UuidV4(), UuidV6(), UuidV7() and
 *   Ulid() without a value, and create() on its random, time-based, default and ULID factories;
 * - uuid_create() of the uuid extension and its polyfill.
 *
 * Name-based UUIDs (versions 3 and 5) and parsing an existing UUID are not reported: they make no
 * new id.
 */
#[Internal]
final readonly class UuidCreations
{
    /**
     * Functions that make a new UUID, lower case.
     *
     * @var list<string>
     */
    public const array FUNCTIONS = [
        'uuid_create',
        'ramsey\uuid\v1',
        'ramsey\uuid\v2',
        'ramsey\uuid\v4',
        'ramsey\uuid\v6',
        'ramsey\uuid\v7',
    ];

    /**
     * Each class, with its subclasses and implementations, and its methods that make a new id,
     * lower case.
     *
     * @var array<string, list<string>>
     */
    public const array METHODS = [
        Str::class => ['uuid', 'uuid7', 'ordereduuid', 'ulid'],
        RamseyUuid::class => ['uuid1', 'uuid2', 'uuid4', 'uuid6', 'uuid7'],
        RamseyUuidFactory::class => ['uuid1', 'uuid2', 'uuid4', 'uuid6', 'uuid7'],
        SymfonyUuid::class => ['v1', 'v4', 'v6', 'v7'],
        UuidV1::class => ['generate'],
        UuidV6::class => ['generate'],
        UuidV7::class => ['generate'],
        Ulid::class => ['generate'],
        SymfonyUuidFactory::class => ['create'],
        RandomBasedUuidFactory::class => ['create'],
        TimeBasedUuidFactory::class => ['create'],
        UlidFactory::class => ['create'],
    ];

    /**
     * The classes whose constructor makes a new id when it gets no value.
     *
     * @var list<string>
     */
    public const array CONSTRUCTORS = [UuidV1::class, UuidV4::class, UuidV6::class, UuidV7::class, Ulid::class];

    /**
     * The names of the value parameter of those constructors.
     *
     * @var list<string>
     */
    public const array VALUE_PARAMETERS = ['uuid', 'ulid'];

    /**
     * The Eloquent traits that make an id for every new model.
     *
     * @var list<string>
     */
    public const array TRAITS = [HasUuids::class, HasVersion4Uuids::class, HasUlids::class];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    /**
     * The expression that makes a new id, as the error names it, or null when the node makes
     * none.
     */
    public function in(Node $node, Scope $scope): ?string
    {
        return match (true) {
            $node instanceof FuncCall => $this->function($node, $scope),
            $node instanceof New_ => $this->construction($node, $scope),
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->methodCall($node, $scope),
            $node instanceof TraitUse => $this->traitUse($node, $scope),
            default => null,
        };
    }

    private function function(FuncCall $call, Scope $scope): ?string
    {
        if (! $call->name instanceof Name) {
            return null;
        }

        $resolved = $this->reflectionProvider->resolveFunctionName($call->name, $scope);

        return $resolved !== null && in_array(strtolower($resolved), self::FUNCTIONS, true)
            ? sprintf('%s()', $resolved)
            : null;
    }

    private function construction(New_ $new, Scope $scope): ?string
    {
        if (! $new->class instanceof Name) {
            return null;
        }

        $class = $this->classNamed($scope->resolveName($new->class));

        if (! $class instanceof ClassReflection || ! $this->isOneOf($class, self::CONSTRUCTORS) || CallArguments::unknown($new)) {
            return null;
        }

        return CallArguments::absentOrNullable($new, 0, self::VALUE_PARAMETERS, $scope)
            ? sprintf('new %s()', $class->getDisplayName())
            : null;
    }

    private function staticCall(StaticCall $call, Scope $scope): ?string
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        if (! $call->class instanceof Name) {
            return $this->onReceiver($scope->getType($call->class)->getObjectClassReflections(), $call->name);
        }

        $class = $this->classNamed($scope->resolveName($call->class));

        return $class instanceof ClassReflection ? $this->onReceiver([$class], $call->name) : null;
    }

    private function methodCall(MethodCall|NullsafeMethodCall $call, Scope $scope): ?string
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        return $this->onReceiver($scope->getType($call->var)->getObjectClassReflections(), $call->name);
    }

    /**
     * @param  list<ClassReflection>  $classes
     */
    private function onReceiver(array $classes, Identifier $name): ?string
    {
        foreach ($classes as $class) {
            foreach (self::METHODS as $receiver => $methods) {
                if (in_array($name->toLowerString(), $methods, true) && $class->is($receiver)) {
                    return sprintf('%s::%s()', $class->getDisplayName(), $name->toString());
                }
            }
        }

        return null;
    }

    private function traitUse(TraitUse $use, Scope $scope): ?string
    {
        foreach ($use->traits as $trait) {
            $class = $this->classNamed($scope->resolveName($trait));

            if ($class instanceof ClassReflection && in_array($class->getName(), self::TRAITS, true)) {
                return sprintf('the trait %s', $class->getDisplayName());
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     */
    private function isOneOf(ClassReflection $class, array $names): bool
    {
        return array_any($names, fn (string $name): bool => $class->is($name));
    }

    private function classNamed(string $name): ?ClassReflection
    {
        return $this->reflectionProvider->hasClass($name) ? $this->reflectionProvider->getClass($name) : null;
    }
}
