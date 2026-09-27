<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Closure;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/*
 * The shared DoctorCheck suite must fail a check that breaks the contract. Each broken check wraps
 * the fake and breaks one rule; the named case must fail on it, and the fake must pass every case.
 */

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

    expect($cases)->toHaveCount(6);
});

it('fails a check that breaks the contract', function (Breach $breach, string $name): void {
    $case = doctorCheckCase($name, fakeChecks($breach));

    expect(fn () => $case->{$name}())->toThrow(AssertionFailedError::class);
})->with([
    'a check that throws' => [Breach::Throws, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a result for another check' => [Breach::AnswersForAnother, 'a_passing_check_returns_a_pass_for_itself'],
    'a result that contradicts blocking' => [Breach::ContradictsBlocking, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a failure that gives the exit code of the other blocking' => [Breach::ContradictsBlocking, 'the_failure_of_a_check_gives_the_exit_code_of_its_blocking_and_kind'],
    'a check that never fails, so its failure gives no exit code' => [Breach::NeverFails, 'the_failure_of_a_check_gives_the_exit_code_of_its_blocking_and_kind'],
    'a check that skips itself' => [Breach::SkipsItself, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a check that never fails' => [Breach::NeverFails, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a cause that is the fix' => [Breach::FixAsCause, 'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix'],
    'a result that changes between runs' => [Breach::ChangesBetweenRuns, 'running_a_check_again_gives_the_same_result'],
    'a check that requires itself' => [Breach::RequiresItself, 'a_check_requires_other_checks_each_once'],
    'an id that changes with the state' => [Breach::ChangesId, 'the_check_keeps_its_id_blocking_and_requirements_in_every_state'],
]);
