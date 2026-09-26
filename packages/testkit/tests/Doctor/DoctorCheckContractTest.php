<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/*
 * The shared DoctorCheck suite must fail a check that breaks the contract. Each broken check wraps
 * the fake and breaks one rule; the named case must fail on it, and the fake must pass every case.
 */

/**
 * How a broken check breaks the contract.
 */
enum Breach
{
    /** run() throws instead of returning a failure. */
    case Throws;

    /** The result names another check. */
    case AnswersForAnother;

    /** The result says the check does not block when the check says it does. */
    case ContradictsBlocking;

    /** The failing state skips itself instead of failing. */
    case SkipsItself;

    /** The failing state passes, so the check cannot see the problem. */
    case NeverFails;

    /** The cause repeats the fix. */
    case FixAsCause;

    /** Every run gives another explanation. */
    case ChangesBetweenRuns;

    /** The check requires itself. */
    case RequiresItself;

    /** The failing state has another id than the passing state. */
    case ChangesId;
}

/**
 * The fake check with one rule broken.
 */
final class BrokenCheck implements DoctorCheck
{
    private int $runs = 0;

    public function __construct(private readonly FakeDoctorCheck $inner, private readonly Breach $breach, private readonly bool $failing) {}

    public function id(): CheckId
    {
        return $this->breach === Breach::ChangesId && $this->failing ? new CheckId('fake.other') : $this->inner->id();
    }

    public function blocking(): bool
    {
        return $this->inner->blocking();
    }

    public function requires(): array
    {
        return $this->breach === Breach::RequiresItself ? [$this->inner->id()] : $this->inner->requires();
    }

    public function run(): CheckResult
    {
        $this->runs++;
        $result = $this->inner->run();
        $id = $this->id();

        return match ($this->breach) {
            Breach::Throws => throw new RuntimeException('Connection refused'),
            Breach::AnswersForAnother => new CheckResult(new CheckId('fake.other'), $result->status, $result->blocking, $result->explanation, $result->failure, $result->code, $result->cause, $result->fix),
            Breach::ContradictsBlocking => new CheckResult($id, $result->status, ! $result->blocking, $result->explanation, $result->failure, $result->code, $result->cause, $result->fix),
            Breach::SkipsItself => $this->failing ? CheckResult::skip($id, $result->blocking, 'Skipped.', 'It chose to.') : $result,
            Breach::NeverFails => CheckResult::pass($id, $result->blocking, 'All good.'),
            Breach::FixAsCause => $result->failed() ? CheckResult::fail($id, $result->blocking, FailureKind::Violation, 'fake_check_failed', 'It fails.', 'Run the fix.', 'Run the fix.') : $result,
            Breach::ChangesBetweenRuns => new CheckResult($id, $result->status, $result->blocking, sprintf('Run %d.', $this->runs), $result->failure, $result->code, $result->cause, $result->fix),
            Breach::RequiresItself, Breach::ChangesId => new CheckResult($id, $result->status, $result->blocking, $result->explanation, $result->failure, $result->code, $result->cause, $result->fix),
        };
    }
}

/**
 * The contract suite with the checks under test injected.
 */
final class InjectedDoctorCheckContract extends TestCase
{
    use DoctorCheckContract;

    /** @var (Closure(bool): DoctorCheck)|null */
    public ?Closure $checks = null;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return ($this->checks ?? throw new LogicException('No checks were injected.'))(false);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return ($this->checks ?? throw new LogicException('No checks were injected.'))(true);
    }
}

/**
 * @param  Closure(bool): DoctorCheck  $checks  the check in its failing state when given true
 */
function doctorCheckCase(string $name, Closure $checks): InjectedDoctorCheckContract
{
    $case = new InjectedDoctorCheckContract($name === '' ? throw new LogicException('A shared case has a name.') : $name);
    $case->checks = $checks;

    return $case;
}

/**
 * @return Closure(bool): DoctorCheck
 */
function fakeChecks(?Breach $breach = null): Closure
{
    return static function (bool $failing) use ($breach): DoctorCheck {
        $id = new CheckId('fake.check');
        $requires = [new CheckId('fake.dependency')];
        $fake = $failing ? FakeDoctorCheck::failing($id, requires: $requires) : FakeDoctorCheck::passing($id, requires: $requires);

        return $breach instanceof Breach ? new BrokenCheck($fake, $breach, $failing) : $fake;
    };
}

/**
 * The names of the shared cases.
 *
 * @return list<string>
 */
function doctorCheckCases(): array
{
    $methods = array_filter(
        new ReflectionClass(DoctorCheckContract::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getAttributes(Test::class) !== [],
    );

    return array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));
}

it('passes the fake on every shared case', function (): void {
    $cases = doctorCheckCases();

    foreach ($cases as $name) {
        doctorCheckCase($name, fakeChecks())->{$name}();
    }

    expect($cases)->toHaveCount(5);
});

it('fails a check that breaks the contract', function (Breach $breach, string $name): void {
    $case = doctorCheckCase($name, fakeChecks($breach));

    expect(fn () => $case->{$name}())->toThrow(AssertionFailedError::class);
})->with([
    'a check that throws' => [Breach::Throws, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a result for another check' => [Breach::AnswersForAnother, 'a_passing_check_returns_a_pass_for_itself'],
    'a result that contradicts blocking' => [Breach::ContradictsBlocking, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a check that skips itself' => [Breach::SkipsItself, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a check that never fails' => [Breach::NeverFails, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a cause that is the fix' => [Breach::FixAsCause, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a result that changes between runs' => [Breach::ChangesBetweenRuns, 'running_a_check_again_gives_the_same_result'],
    'a check that requires itself' => [Breach::RequiresItself, 'a_check_requires_other_checks_each_once'],
    'an id that changes with the state' => [Breach::ChangesId, 'the_check_keeps_its_id_blocking_and_requirements_in_every_state'],
]);
