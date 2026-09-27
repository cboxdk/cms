<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Override;

/**
 * The php.ini settings of this process, from ini_get().
 */
#[Internal]
final readonly class IniPhpSettingsProbe implements PhpSettingsProbe
{
    #[Override]
    public function allowUrlFopen(): bool
    {
        // ini_get() gives "1" or "" for a boolean setting, or the raw value from php.ini or -d.
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }
}
