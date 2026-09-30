<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The form the entry writers give Postgres an instant in: UTC with its microseconds.
 */
#[Internal]
final readonly class Timestamps
{
    public static function of(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
