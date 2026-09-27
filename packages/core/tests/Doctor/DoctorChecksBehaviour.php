<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every DoctorChecks does, run against OrderedDoctorChecks and FakeDoctorChecks, so the fake
 * the doctor's action tests use cannot drift from the list the application builds (GUARDRAILS 9).
 */
trait DoctorChecksBehaviour
{
    /**
     * The implementation under test with these lists.
     *
     * @param  list<DoctorCheck>  $runtime
     * @param  list<DoctorCheck>  $dev
     *
     * @throws InvalidDoctorCheck
     */
    abstract protected function doctorChecks(array $runtime, array $dev): DoctorChecks;

    #[Test]
    public function without_dev_it_gives_exactly_the_runtime_checks_in_order(): void
    {
        [$php, $postgres, $node] = $this->sampleChecks();
        $checks = $this->doctorChecks([$php, $postgres], [$node]);

        Assert::assertSame([$php, $postgres], $checks->for(new DoctorRunOptions));
        Assert::assertSame([$php, $postgres], $checks->for(new DoctorRunOptions), 'Asking again gives the same checks.');
    }

    #[Test]
    public function with_dev_it_gives_the_runtime_checks_and_then_the_dev_checks(): void
    {
        [$php, $postgres, $node] = $this->sampleChecks();

        Assert::assertSame([$php, $postgres, $node], $this->doctorChecks([$php, $postgres], [$node])->for(new DoctorRunOptions(dev: true)));
    }

    #[Test]
    public function empty_lists_give_no_checks(): void
    {
        $checks = $this->doctorChecks([], []);

        Assert::assertSame([], $checks->for(new DoctorRunOptions));
        Assert::assertSame([], $checks->for(new DoctorRunOptions(dev: true)));
    }

    #[Test]
    public function a_dev_check_may_require_a_runtime_check(): void
    {
        $php = FakeDoctorCheck::passing(new CheckId('fake.php'));
        $node = FakeDoctorCheck::passing(new CheckId('fake.node'), false, [new CheckId('fake.php')]);

        Assert::assertSame([$php, $node], $this->doctorChecks([$php], [$node])->for(new DoctorRunOptions(dev: true)));
    }

    #[Test]
    public function it_refuses_an_id_listed_twice_in_one_list_or_across_both(): void
    {
        $a = FakeDoctorCheck::passing(new CheckId('fake.a'));
        $again = FakeDoctorCheck::failing(new CheckId('fake.a'));

        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$a, $again], []), 'The check "fake.a" is listed twice.');
        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$a], [$again]), 'The check "fake.a" is listed twice.');
    }

    #[Test]
    public function it_refuses_a_requirement_that_is_not_listed_before_the_check(): void
    {
        $a = FakeDoctorCheck::passing(new CheckId('fake.a'));
        $needsA = FakeDoctorCheck::passing(new CheckId('fake.b'), true, [new CheckId('fake.a')]);
        $needsMissing = FakeDoctorCheck::passing(new CheckId('fake.c'), true, [new CheckId('fake.missing')]);
        $needsDev = FakeDoctorCheck::passing(new CheckId('fake.d'), true, [new CheckId('fake.dev')]);
        $dev = FakeDoctorCheck::passing(new CheckId('fake.dev'), false);

        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$needsA, $a], []), 'The check "fake.b" requires "fake.a", which is not listed before it.');
        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([], [$needsMissing]), 'The check "fake.c" requires "fake.missing", which is not listed before it.');
        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$needsDev], [$dev]), 'The check "fake.d" requires "fake.dev", which is not listed before it.');
    }

    #[Test]
    public function it_refuses_a_blocking_check_that_requires_one_that_does_not_block(): void
    {
        $readiness = FakeDoctorCheck::passing(new CheckId('fake.readiness'), false);
        $blocking = FakeDoctorCheck::passing(new CheckId('fake.blocking'), true, [new CheckId('fake.readiness')]);
        $dev = FakeDoctorCheck::passing(new CheckId('fake.dev'), true, [new CheckId('fake.readiness')]);

        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$readiness, $blocking], []), 'The blocking check "fake.blocking" requires "fake.readiness", which does not block.');
        $this->assertRefused(fn (): DoctorChecks => $this->doctorChecks([$readiness], [$dev]), 'The blocking check "fake.dev" requires "fake.readiness", which does not block.');
    }

    #[Test]
    public function a_check_that_does_not_block_may_require_checks_of_either_kind(): void
    {
        $blocking = FakeDoctorCheck::passing(new CheckId('fake.blocking'));
        $readiness = FakeDoctorCheck::passing(new CheckId('fake.readiness'), false, [new CheckId('fake.blocking')]);
        $later = FakeDoctorCheck::passing(new CheckId('fake.later'), false, [new CheckId('fake.readiness'), new CheckId('fake.blocking')]);

        Assert::assertSame([$blocking, $readiness, $later], $this->doctorChecks([$blocking, $readiness, $later], [])->for(new DoctorRunOptions));
    }

    /**
     * @return array{FakeDoctorCheck, FakeDoctorCheck, FakeDoctorCheck}
     */
    private function sampleChecks(): array
    {
        return [
            FakeDoctorCheck::passing(new CheckId('fake.php')),
            FakeDoctorCheck::passing(new CheckId('fake.postgres'), true, [new CheckId('fake.php')]),
            FakeDoctorCheck::passing(new CheckId('fake.node'), false),
        ];
    }

    /**
     * @param  Closure(): DoctorChecks  $make
     */
    private function assertRefused(Closure $make, string $message): void
    {
        try {
            $make();
        } catch (InvalidDoctorCheck $refused) {
            Assert::assertStringContainsString($message, $refused->getMessage());

            return;
        }

        Assert::fail(sprintf('The list was accepted; expected InvalidDoctorCheck with "%s".', $message));
    }
}
