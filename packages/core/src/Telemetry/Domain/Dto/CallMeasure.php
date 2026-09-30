<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Telemetry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Core\Telemetry\Domain\ActionKind;
use DateTimeImmutable;

/**
 * One call of an action as its telemetry measures it: which pipeline runs it, the action's name
 * and version, when it started by the Clock, the Stopwatch's reading at the start, and the
 * attributes of its span known before it runs, such as the correlation id.
 */
#[Internal]
final readonly class CallMeasure
{
    /**
     * @param  list<Attribute>  $context
     */
    public function __construct(
        public ActionKind $kind,
        public CommandName $name,
        public int $version,
        public DateTimeImmutable $start,
        public int $from,
        public array $context,
    ) {}
}
