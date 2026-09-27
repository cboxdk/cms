<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Carbon\CarbonInterface;
use Carbon\Factory as CarbonFactory;
use Cbox\Cms\Contracts\Attributes\Internal;
use Closure;
use DateTimeInterface;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\InteractsWithTime;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use Symfony\Component\Clock\Clock as SymfonyClock;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Finds the expressions that read the system's wall clock, for SystemClockRule (GUARDRAILS 2.3).
 *
 * - time(), microtime(), gettimeofday(), uniqid() and Laravel's now() and today();
 * - date(), gmdate(), idate(), strftime(), gmstrftime(), getdate() and localtime() without a
 *   timestamp, and mktime() and gmmktime() without all six parts;
 * - strtotime() without a base timestamp, and new DateTime(), new DateTimeImmutable(),
 *   date_create(), date_create_immutable(), new Carbon() and Carbon::parse() without a date,
 *   with a date that may be null, or with a date string that depends on the current time, such
 *   as "now", "today" or "+1 day" (DateStrings::dependsOnNow());
 * - createFromFormat() and the date_create_*from_format() functions with a format that takes a
 *   field from the current time (DateStrings::formatDependsOnNow());
 * - Carbon's factories on a Carbon class, a Carbon or Laravel date factory and the Date
 *   facade: now(), today(), tomorrow(), yesterday(), createFromTime(), createFromTimeString(),
 *   createFromDate(), create() without arguments and createMidnightDate() without all three
 *   parts;
 * - the Carbon methods that compare with the current time: isPast(), isFuture(), isToday(),
 *   isCurrentMonth() and the like, ago(), fromNow(), toNow(), and diff(), diffIn*() and
 *   diffForHumans() without the other date;
 * - symfony/clock's now() function, now() on its NativeClock, MonotonicClock and Clock, and
 *   Clock::get(), the global clock;
 * - the traits InteractsWithTime of Laravel and ClockAwareTrait of symfony/clock, whose methods
 *   read the clock;
 * - $_SERVER['REQUEST_TIME'] and $_SERVER['REQUEST_TIME_FLOAT'].
 *
 * A date string or format that is not a constant is not reported: a Boundary parses input.
 * hrtime() is not reported: it is the monotonic clock for durations and deadlines of real waits,
 * which the Clock contract does not give (packages/contracts/docs/clock.md).
 */
#[Internal]
final readonly class ClockReads
{
    /**
     * Functions that always read the clock, lower case. now() and today() are Laravel's helpers,
     * Symfony\Component\Clock\now() is symfony/clock's.
     *
     * @var list<string>
     */
    public const array FUNCTIONS = ['time', 'microtime', 'gettimeofday', 'uniqid', 'now', 'today', 'symfony\\component\\clock\\now'];

    /**
     * Functions that read the clock unless a timestamp is passed, each with the position of its
     * $timestamp parameter.
     *
     * @var array<string, int>
     */
    public const array TIMESTAMP_FUNCTIONS = [
        'date' => 1,
        'gmdate' => 1,
        'idate' => 1,
        'strftime' => 1,
        'gmstrftime' => 1,
        'getdate' => 0,
        'localtime' => 0,
    ];

    /**
     * Functions whose missing or null parts are taken from the current time, with the names of
     * the parts in order.
     *
     * @var list<string>
     */
    public const array PART_FUNCTIONS = ['mktime', 'gmmktime'];

    /** @var list<string> */
    public const array PARTS = ['hour', 'minute', 'second', 'month', 'day', 'year'];

    /**
     * Functions that parse a date string, which is the current time when it is left out.
     *
     * @var list<string>
     */
    public const array PARSE_FUNCTIONS = ['date_create', 'date_create_immutable'];

    /**
     * Functions that parse a date with a format, like createFromFormat().
     *
     * @var list<string>
     */
    public const array FORMAT_FUNCTIONS = ['date_create_from_format', 'date_create_immutable_from_format'];

    /**
     * The names of the date string parameter: DateTime's $datetime and Carbon's $time.
     *
     * @var list<string>
     */
    public const array DATE_PARAMETERS = ['datetime', 'time'];

    /**
     * Carbon's factory methods that always read the clock, lower case.
     *
     * @var list<string>
     */
    public const array FACTORY_METHODS = ['now', 'today', 'tomorrow', 'yesterday', 'createfromtime', 'createfromtimestring', 'createfromdate'];

    /**
     * Carbon's factory methods that parse a date string, the current time when it is left out.
     *
     * @var list<string>
     */
    public const array FACTORY_PARSE_METHODS = ['parse', 'rawparse'];

    /**
     * Carbon's methods that compare with the current time, lower case.
     *
     * @var list<string>
     */
    public const array COMPARE_METHODS = ['ispast', 'isfuture', 'isnoworpast', 'isnoworfuture', 'istoday', 'istomorrow', 'isyesterday', 'ago', 'fromnow', 'tonow'];

    /** Carbon's isCurrentMonth(), isNextWeek(), isLastYear() and the like. */
    public const string COMPARE_UNIT_PATTERN = '/^is(current|next|last)(millennium|century|decade|year|quarter|month|week|day|hour|minute|second|millisecond|microsecond)$/';

    /**
     * The parameter names of the other date in Carbon's diff methods.
     *
     * @var list<string>
     */
    public const array OTHER_DATE_PARAMETERS = ['date', 'other'];

    /**
     * The keys of $_SERVER that hold the time the request started.
     *
     * @var list<string>
     */
    public const array SERVER_KEYS = ['REQUEST_TIME', 'REQUEST_TIME_FLOAT'];

    /**
     * The system clocks of symfony/clock, with their subclasses, and their methods that read the
     * clock or give the global clock, which is the system clock unless a test replaced it.
     *
     * @var array<string, list<string>>
     */
    public const array SYSTEM_CLOCKS = [
        NativeClock::class => ['now'],
        MonotonicClock::class => ['now'],
        SymfonyClock::class => ['now', 'get'],
    ];

    /**
     * The traits whose methods read the clock: Laravel's currentTime() and availableAt(), and
     * symfony/clock's now(), which falls back to the system clock.
     *
     * @var list<string>
     */
    public const array TRAITS = [InteractsWithTime::class, ClockAwareTrait::class];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    /**
     * The expression that reads the clock, as the error names it, or null when the node does
     * not read the clock.
     */
    public function in(Node $node, Scope $scope): ?string
    {
        return match (true) {
            $node instanceof FuncCall => $this->function($node, $scope),
            $node instanceof New_ => $this->construction($node, $scope),
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->methodCall($node, $scope),
            $node instanceof ArrayDimFetch => $this->server($node, $scope),
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

        if ($resolved === null) {
            return null;
        }

        $function = strtolower($resolved);
        $described = sprintf('%s()', $resolved);

        if (in_array($function, self::FUNCTIONS, true)) {
            return $described;
        }

        $check = match (true) {
            array_key_exists($function, self::TIMESTAMP_FUNCTIONS) => fn (): bool => CallArguments::absentOrNullable($call, self::TIMESTAMP_FUNCTIONS[$function], ['timestamp'], $scope),
            in_array($function, self::PART_FUNCTIONS, true) => fn (): bool => $this->partsMissing($call, self::PARTS, $scope),
            $function === 'strtotime' => fn (): bool => CallArguments::absentOrNullable($call, 1, ['baseTimestamp'], $scope)
                && $this->dateReadsNow(CallArguments::find($call, 0, ['datetime']), $scope),
            in_array($function, self::PARSE_FUNCTIONS, true) => fn (): bool => $this->dateReadsNow(CallArguments::find($call, 0, ['datetime']), $scope),
            in_array($function, self::FORMAT_FUNCTIONS, true) => fn (): bool => $this->formatReadsNow($call, $scope),
            default => null,
        };

        return $check !== null && $this->reads($call, $check) ? $described : null;
    }

    /**
     * Whether a call of a function or method that reads the clock only with some arguments
     * does. A first-class callable, such as date(...), can be called without them, so it
     * counts; a call with unpacked arguments cannot be read and does not.
     *
     * @param  Closure(): bool  $check
     */
    private function reads(CallLike $call, Closure $check): bool
    {
        if ($call->isFirstClassCallable()) {
            return true;
        }

        return ! CallArguments::unknown($call) && $check();
    }

    private function construction(New_ $new, Scope $scope): ?string
    {
        if (! $new->class instanceof Name) {
            return null;
        }

        $class = $this->classNamed($scope->resolveName($new->class));

        if (! $class instanceof ClassReflection || ! $class->is(DateTimeInterface::class) || CallArguments::unknown($new)) {
            return null;
        }

        return $this->dateReadsNow(CallArguments::find($new, 0, self::DATE_PARAMETERS), $scope)
            ? sprintf('new %s()', $class->getDisplayName())
            : null;
    }

    private function staticCall(StaticCall $call, Scope $scope): ?string
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        if (! $call->class instanceof Name) {
            return $this->onReceiver($scope->getType($call->class)->getObjectClassReflections(), $call, $call->name, $scope);
        }

        $class = $this->classNamed($scope->resolveName($call->class));

        return $class instanceof ClassReflection ? $this->onReceiver([$class], $call, $call->name, $scope) : null;
    }

    private function methodCall(MethodCall|NullsafeMethodCall $call, Scope $scope): ?string
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        return $this->onReceiver($scope->getType($call->var)->getObjectClassReflections(), $call, $call->name, $scope);
    }

    /**
     * @param  list<ClassReflection>  $classes
     */
    private function onReceiver(array $classes, CallLike $call, Identifier $name, Scope $scope): ?string
    {
        foreach ($classes as $class) {
            if ($this->readsOn($class, $call, $name->toLowerString(), $scope)) {
                return sprintf('%s::%s()', $class->getDisplayName(), $name->toString());
            }
        }

        return null;
    }

    private function readsOn(ClassReflection $class, CallLike $call, string $method, Scope $scope): bool
    {
        foreach (self::SYSTEM_CLOCKS as $clock => $methods) {
            if (in_array($method, $methods, true) && $class->is($clock)) {
                return true;
            }
        }

        $carbon = $class->is(CarbonInterface::class);
        $factory = $carbon || $class->is(CarbonFactory::class) || $class->is(DateFactory::class) || $class->is(Date::class);

        if (! $factory && ! $class->is(DateTimeInterface::class)) {
            return false;
        }

        if (($factory && in_array($method, self::FACTORY_METHODS, true)) || ($carbon && $this->comparesWithNow($method))) {
            return true;
        }

        $check = match (true) {
            $method === 'createfromformat' => fn (): bool => $this->formatReadsNow($call, $scope),
            ! $factory => null,
            $method === 'create' => fn (): bool => $call->getArgs() === [],
            $method === 'createmidnightdate' => fn (): bool => $this->partsMissing($call, ['year', 'month', 'day'], $scope),
            in_array($method, self::FACTORY_PARSE_METHODS, true) => fn (): bool => $this->dateReadsNow(CallArguments::find($call, 0, self::DATE_PARAMETERS), $scope),
            $carbon && $this->diffs($method) => fn (): bool => CallArguments::absentOrNullable($call, 0, self::OTHER_DATE_PARAMETERS, $scope),
            default => null,
        };

        return $check !== null && $this->reads($call, $check);
    }

    private function comparesWithNow(string $method): bool
    {
        return in_array($method, self::COMPARE_METHODS, true)
            || str_contains($method, 'tonow')
            || preg_match(self::COMPARE_UNIT_PATTERN, $method) === 1;
    }

    private function diffs(string $method): bool
    {
        return $method === 'diff'
            || str_starts_with($method, 'diffin')
            || str_starts_with($method, 'floatdiffin')
            || str_ends_with($method, 'diffforhumans');
    }

    private function server(ArrayDimFetch $fetch, Scope $scope): ?string
    {
        if (! $fetch->var instanceof Variable || $fetch->var->name !== '_SERVER' || ! $fetch->dim instanceof Expr) {
            return null;
        }

        foreach ($scope->getType($fetch->dim)->getConstantStrings() as $key) {
            if (in_array($key->getValue(), self::SERVER_KEYS, true)) {
                return sprintf("\$_SERVER['%s']", $key->getValue());
            }
        }

        return null;
    }

    /**
     * True when the date string is left out, may be null or is a constant that depends on the
     * current time.
     */
    private function dateReadsNow(?Expr $value, Scope $scope): bool
    {
        if (! $value instanceof Expr) {
            return true;
        }

        $type = $scope->getType($value);

        if (! $type->isNull()->no()) {
            return true;
        }

        return array_any($type->getConstantStrings(), fn (ConstantStringType $string): bool => DateStrings::dependsOnNow($string->getValue()));
    }

    private function formatReadsNow(CallLike $call, Scope $scope): bool
    {
        $format = CallArguments::find($call, 0, ['format']);

        if (! $format instanceof Expr) {
            return false;
        }

        return array_any($scope->getType($format)->getConstantStrings(), fn (ConstantStringType $string): bool => DateStrings::formatDependsOnNow($string->getValue()));
    }

    /**
     * True when a part is left out or may be null.
     *
     * @param  list<string>  $parts
     */
    private function partsMissing(CallLike $call, array $parts, Scope $scope): bool
    {
        return array_any($parts, fn (string $part, int $position): bool => CallArguments::absentOrNullable($call, $position, [$part], $scope));
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

    private function classNamed(string $name): ?ClassReflection
    {
        return $this->reflectionProvider->hasClass($name) ? $this->reflectionProvider->getClass($name) : null;
    }
}
