<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the kernel's settings for idempotency keys from `cbox-cms.idempotency`:
 *
 *     'idempotency' => [
 *         'wait_budget_ms' => 2000,   // 0 to WaitBudget::MAX_MILLISECONDS
 *     ],
 */
#[Internal]
final readonly class IdempotencyConfig
{
    public const string CONFIG_KEY = 'cbox-cms.idempotency';

    /** The default of wait_budget_ms: 2 of the command transaction's 5 seconds (GUARDRAILS 4.1). */
    public const int DEFAULT_WAIT_BUDGET_MS = 2000;

    /**
     * @throws InvalidArgumentException when wait_budget_ms is not a whole number from 0 to WaitBudget::MAX_MILLISECONDS
     */
    public static function read(Repository $config): IdempotencySettings
    {
        $value = $config->get(self::CONFIG_KEY.'.wait_budget_ms', self::DEFAULT_WAIT_BUDGET_MS);

        if (! is_int($value) || $value < 0 || $value > WaitBudget::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf(
                'The setting %s.wait_budget_ms must be a whole number of milliseconds from 0 to %d; it is %s.',
                self::CONFIG_KEY,
                WaitBudget::MAX_MILLISECONDS,
                is_int($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return new IdempotencySettings(WaitBudget::milliseconds($value));
    }
}
