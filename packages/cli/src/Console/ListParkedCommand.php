<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\SubscriptionArguments;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Subscriptions\Actions\ListParked;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedFilter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `cms:events:parked`: lists the aggregates subscriptions have parked (PRD 7.8), one line each:
 * the subscription, the aggregate, the tries, when it was parked and, once released, when.
 *
 * Exit codes: 0, 64 an invalid subscription name.
 */
#[Internal]
#[Description('List the aggregates subscriptions have parked')]
#[Signature('cms:events:parked
        {subscription? : Only this subscription}')]
final class ListParkedCommand extends Command
{
    public function handle(ListParked $parked): int
    {
        $subscription = $this->argument('subscription');

        try {
            $filter = new ParkedFilter($subscription === null ? null : SubscriptionArguments::subscription($subscription));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        $rows = $parked->list($filter);

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s %s %d tries, parked %s%s',
                $row->subscription->value,
                $row->aggregate->toString(),
                $row->attempts,
                $row->parkedAt->format('Y-m-d\TH:i:s\Z'),
                $row->releasedAt === null ? '' : ', released '.$row->releasedAt->format('Y-m-d\TH:i:s\Z'),
            ));
        }

        $this->info(sprintf('%d parked.', count($rows)));

        return self::SUCCESS;
    }
}
