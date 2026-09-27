<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;

/**
 * php.ini settings as the test sets them; allow_url_fopen is off until the test turns it on.
 */
final class FakePhpSettingsProbe implements PhpSettingsProbe
{
    public function __construct(public bool $allowUrlFopen = false) {}

    public function allowUrlFopen(): bool
    {
        return $this->allowUrlFopen;
    }
}
