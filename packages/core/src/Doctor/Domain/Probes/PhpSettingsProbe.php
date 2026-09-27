<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The php.ini settings of this process that the runtime contract fixes.
 */
#[Internal]
interface PhpSettingsProbe
{
    /** Whether allow_url_fopen is on, so the file functions also fetch http:// and ftp:// URLs. */
    public function allowUrlFopen(): bool;
}
