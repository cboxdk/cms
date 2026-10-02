<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\SessionCookieCheck;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SameSite;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeSessionCookieProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against SessionCookieCheck, with a fake probe: the
 * production cookie in production against the development cookie there.
 */
final class SessionCookieDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new SessionCookieCheck(new FakeSessionCookieProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new SessionCookieCheck(new FakeSessionCookieProbe(cookie: new SessionCookie(SessionCookie::DEVELOPMENT_NAME, false, SameSite::Lax)));
    }
}
