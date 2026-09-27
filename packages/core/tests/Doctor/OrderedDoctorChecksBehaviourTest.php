<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Core\Doctor\Domain\OrderedDoctorChecks;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DoctorChecksBehaviour against OrderedDoctorChecks, the list CoreServiceProvider builds, and
 * with(), which adds the checks an application or addon names after the core's.
 */
final class OrderedDoctorChecksBehaviourTest extends TestCase
{
    use DoctorChecksBehaviour;

    #[Test]
    public function with_adds_runtime_checks_after_the_runtime_checks_and_dev_checks_after_the_dev_checks(): void
    {
        $php = FakeDoctorCheck::passing(new CheckId('fake.php'));
        $node = FakeDoctorCheck::passing(new CheckId('fake.node'), false);
        $addon = FakeDoctorCheck::passing(new CheckId('addon.ready'), false, [new CheckId('fake.php')]);
        $tool = FakeDoctorCheck::passing(new CheckId('addon.tool'), false, [new CheckId('fake.node'), new CheckId('addon.ready')]);
        $core = new OrderedDoctorChecks([$php], [$node]);

        $checks = $core->with([$addon], [$tool]);

        self::assertSame([$php, $addon], $checks->for(new DoctorRunOptions));
        self::assertSame([$php, $addon, $node, $tool], $checks->for(new DoctorRunOptions(dev: true)));
        self::assertSame([$php, $node], $core->for(new DoctorRunOptions(dev: true)), 'with() leaves the list it was called on as it was.');
    }

    #[Test]
    public function with_checks_the_added_checks_by_the_same_rules(): void
    {
        $core = new OrderedDoctorChecks([FakeDoctorCheck::passing(new CheckId('fake.php'))], [FakeDoctorCheck::passing(new CheckId('fake.node'), false)]);

        $this->expectException(InvalidDoctorCheck::class);
        $this->expectExceptionMessage('The check "addon.ready" requires "fake.node", which is not listed before it.');

        $core->with([FakeDoctorCheck::passing(new CheckId('addon.ready'), false, [new CheckId('fake.node')])], []);
    }

    #[Test]
    public function with_refuses_an_added_check_that_repeats_a_core_id(): void
    {
        $core = new OrderedDoctorChecks([FakeDoctorCheck::passing(new CheckId('fake.php'))], []);

        $this->expectException(InvalidDoctorCheck::class);
        $this->expectExceptionMessage('The check "fake.php" is listed twice.');

        $core->with([], [FakeDoctorCheck::failing(new CheckId('fake.php'))]);
    }

    #[Override]
    protected function doctorChecks(array $runtime, array $dev): DoctorChecks
    {
        return new OrderedDoctorChecks($runtime, $dev);
    }
}
