<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor\Fakes;

use Cbox\Cms\Identity\Doctor\Domain\Probes\SessionCookieProbe;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SameSite;
use Override;

/**
 * The production cookie in production until the test says otherwise; an invalid setting is given
 * as the exception the reader would throw.
 */
final class FakeSessionCookieProbe implements SessionCookieProbe
{
    public function __construct(
        public string $environment = 'production',
        public ?SessionCookie $cookie = new SessionCookie(SessionCookie::PRODUCTION_NAME, true, SameSite::Lax),
        public ?InvalidSessionCookie $invalid = null,
    ) {}

    #[Override]
    public function environment(): string
    {
        return $this->environment;
    }

    #[Override]
    public function cookie(): SessionCookie
    {
        if ($this->invalid instanceof InvalidSessionCookie) {
            throw $this->invalid;
        }

        return $this->cookie ?? throw InvalidSessionCookie::key($this->environment, 'a map with name, secure and same_site');
    }
}
