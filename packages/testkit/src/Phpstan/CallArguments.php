<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;

/**
 * Finds the argument a call passes for one parameter, by its position or by its name, for the
 * rules that decide from the arguments whether a date function reads the system clock
 * (SystemClockRule) or a UUID class makes a new id (UuidCreationRule).
 */
#[Internal]
final class CallArguments
{
    /**
     * True when the arguments cannot be read from the call: a first-class callable such as
     * date(...), or an unpacked argument such as date(...$arguments).
     */
    public static function unknown(CallLike $call): bool
    {
        if ($call->isFirstClassCallable()) {
            return true;
        }

        return array_any($call->getArgs(), fn (Arg $argument): bool => $argument->unpack);
    }

    /**
     * The expression passed for the parameter at this position or with one of these names, or
     * null when the call leaves the parameter to its default. Call unknown() first.
     *
     * @param  list<string>  $names
     */
    public static function find(CallLike $call, int $position, array $names): ?Expr
    {
        foreach (array_values($call->getArgs()) as $index => $argument) {
            if (self::matches($argument, $index, $position, $names)) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * True when the call leaves the parameter to its default, or passes a value that may be
     * null. For the date functions, both mean the current time.
     *
     * @param  list<string>  $names
     */
    public static function absentOrNullable(CallLike $call, int $position, array $names, Scope $scope): bool
    {
        $value = self::find($call, $position, $names);

        return ! $value instanceof Expr || ! $scope->getType($value)->isNull()->no();
    }

    /**
     * @param  list<string>  $names
     */
    private static function matches(Arg $argument, int $index, int $position, array $names): bool
    {
        if (! $argument->name instanceof Identifier) {
            return $index === $position;
        }

        return in_array($argument->name->toString(), $names, true);
    }
}
