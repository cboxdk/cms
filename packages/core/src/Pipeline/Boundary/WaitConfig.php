<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads how long a command waits for its wait level (PRD 8.4) from `cbox-cms.receipts`:
 *
 *     'receipts' => [
 *         'wait_budget_ms' => 5000,   // 0 to WaitSettings::MAX_MILLISECONDS
 *     ],
 */
#[Internal]
final readonly class WaitConfig
{
    public const string CONFIG_KEY = 'cbox-cms.receipts';

    /** The default of wait_budget_ms: the 5 seconds PRD 8.4 gives a breaking news publish. */
    public const int DEFAULT_WAIT_BUDGET_MS = 5000;

    /**
     * @throws InvalidArgumentException when wait_budget_ms is not a whole number from 0 to WaitSettings::MAX_MILLISECONDS
     */
    public static function read(Repository $config): WaitSettings
    {
        $value = $config->get(self::CONFIG_KEY.'.wait_budget_ms', self::DEFAULT_WAIT_BUDGET_MS);

        if (! is_int($value) || $value < 0 || $value > WaitSettings::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf(
                'The setting %s.wait_budget_ms must be a whole number of milliseconds from 0 to %d; it is %s.',
                self::CONFIG_KEY,
                WaitSettings::MAX_MILLISECONDS,
                is_int($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return new WaitSettings($value);
    }
}
