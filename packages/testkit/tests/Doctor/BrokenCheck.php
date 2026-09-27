<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use RuntimeException;

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
