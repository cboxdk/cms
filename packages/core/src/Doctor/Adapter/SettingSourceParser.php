<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;

/**
 * Reads pg_settings.source, or a source the lc_messages probe derives in SQL, into a
 * SettingSource. A text that is not one of Postgres' sources means the server is not the one the
 * doctor knows, so the probe fails instead of guessing.
 */
#[Internal]
final class SettingSourceParser
{
    /**
     * @throws ProbeFailed violation for a source Postgres does not have
     */
    public static function parse(string $setting, string $source): SettingSource
    {
        return SettingSource::tryFrom($source) ?? throw ProbeFailed::violation(sprintf(
            'Postgres reports the source "%s" for %s, which is not one of the sources in pg_settings: %s.',
            $source,
            $setting,
            implode(', ', array_map(static fn (SettingSource $known): string => $known->value, SettingSource::cases())),
        ));
    }
}
