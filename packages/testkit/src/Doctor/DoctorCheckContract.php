<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The shared contract suite for DoctorCheck (GUARDRAILS 2.3 and 9): the invariants of a check and
 * its results, and the exit code its failure gives: 75 or 78 for a blocking check, by the failure
 * kind, and 79 for a check that only affects readiness (PRD 3.3). FakeDoctorCheck and every real
 * check run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return the
 * same check twice: once in a state where it passes and once in a state where it fails, for
 * example with fake probes behind it.
 *
 *     final class PhpVersionDoctorCheckContractTest extends TestCase
 *     {
 *         use DoctorCheckContract;
 *
 *         protected function passingDoctorCheck(): DoctorCheck
 *         {
 *             return new PhpVersionCheck(new FakeRuntimeProbe(php: '8.5.1'));
 *         }
 *
 *         protected function failingDoctorCheck(): DoctorCheck
 *         {
 *             return new PhpVersionCheck(new FakeRuntimeProbe(php: '8.4.9'));
 *         }
 *     }
 */
#[Experimental]
trait DoctorCheckContract
{
    /**
     * The check under test, in a state where run() passes.
     */
    abstract protected function passingDoctorCheck(): DoctorCheck;

    /**
     * The same check, in a state where run() fails.
     */
    abstract protected function failingDoctorCheck(): DoctorCheck;

    #[Test]
    public function the_check_keeps_its_id_blocking_and_requirements_in_every_state(): void
    {
        $passing = $this->passingDoctorCheck();
        $failing = $this->failingDoctorCheck();

        Assert::assertSame($passing->id()->value, $passing->id()->value);
        Assert::assertSame($passing->id()->value, $failing->id()->value, 'The passing and the failing state are the same check.');
        Assert::assertSame($passing->blocking(), $failing->blocking());
        Assert::assertSame(self::ids($passing->requires()), self::ids($failing->requires()));
        Assert::assertSame(self::ids($passing->requires()), self::ids($passing->requires()));
    }

    #[Test]
    public function a_check_requires_other_checks_each_once(): void
    {
        $check = $this->passingDoctorCheck();
        $required = self::ids($check->requires());

        Assert::assertNotContains($check->id()->value, $required, 'A check cannot require itself.');
        Assert::assertSame(array_values(array_unique($required)), $required, 'A check lists each requirement once.');
    }

    #[Test]
    public function a_passing_check_returns_a_pass_for_itself(): void
    {
        $check = $this->passingDoctorCheck();
        $result = self::runCheck($check);

        self::assertAnswersFor($check, $result);
        Assert::assertSame(CheckStatus::Pass, $result->status, sprintf('Expected a pass, got: %s %s', $result->explanation, $result->cause ?? ''));
        Assert::assertNull($result->failure);
        Assert::assertNull($result->code);
        Assert::assertNull($result->cause);
        Assert::assertNull($result->fix);
    }

    #[Test]
    public function a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix(): void
    {
        $check = $this->failingDoctorCheck();
        $result = self::runCheck($check);

        self::assertAnswersFor($check, $result);
        Assert::assertSame(CheckStatus::Fail, $result->status, sprintf('Expected a failure, got: %s', $result->explanation));
        Assert::assertNotNull($result->failure);
        Assert::assertMatchesRegularExpression(CheckResult::CODE_PATTERN, (string) $result->code);
        Assert::assertNotSame('', trim((string) $result->cause));
        Assert::assertNotSame('', trim((string) $result->fix));
        Assert::assertNotSame($result->cause, $result->fix, 'The fix says what to do, not what went wrong.');
        Assert::assertNotSame($result->explanation, $result->cause, 'The cause is the concrete cause, not the explanation again.');
    }

    #[Test]
    public function the_failure_of_a_check_gives_the_exit_code_of_its_blocking_and_kind(): void
    {
        $check = $this->failingDoctorCheck();
        $failing = self::runCheck($check);
        $expected = match (true) {
            ! $check->blocking() => DoctorExitCode::NotReady,
            $failing->failure === FailureKind::Unavailable => DoctorExitCode::Unavailable,
            default => DoctorExitCode::Violation,
        };

        Assert::assertSame(DoctorExitCode::Ok, DoctorExitCode::for([self::runCheck($this->passingDoctorCheck())]), 'A pass leaves cms:doctor at exit 0.');
        Assert::assertSame($expected, DoctorExitCode::for([$failing]), sprintf(
            'A failure of %s, which %s, gives exit %d.',
            $check->id()->value,
            $check->blocking() ? 'blocks the kernel from starting' : 'only affects readiness',
            $expected->value,
        ));
        Assert::assertSame(! $check->blocking(), DoctorExitCode::for([$failing])->allowsStart(), 'The kernel may start while the check fails exactly when the check does not block.');
    }

    #[Test]
    public function running_a_check_again_gives_the_same_result(): void
    {
        foreach ([$this->passingDoctorCheck(), $this->failingDoctorCheck()] as $check) {
            Assert::assertEquals(self::runCheck($check), self::runCheck($check), sprintf('%s looks and changes nothing, so a second run gives the same result.', $check->id()->value));
        }
    }

    private static function runCheck(DoctorCheck $check): CheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $thrown) {
            Assert::fail(sprintf('%s threw %s from run(): %s. A check returns a failure instead.', $check->id()->value, $thrown::class, $thrown->getMessage()));
        }
    }

    private static function assertAnswersFor(DoctorCheck $check, CheckResult $result): void
    {
        Assert::assertSame($check->id()->value, $result->id->value, 'The result carries the check\'s own id.');
        Assert::assertSame($check->blocking(), $result->blocking, 'The result carries the check\'s own blocking.');
        Assert::assertNotSame('', trim($result->explanation));
    }

    /**
     * @param  list<CheckId>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (CheckId $id): string => $id->value, $ids);
    }
}
