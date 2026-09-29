<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\SubscriptionArguments;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Subscriptions\Actions\ReleaseParked;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedRelease;
use Cbox\Cms\Core\Subscriptions\Domain\ReleaseRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `cms:events:release`: releases an aggregate a subscription parked, once its fault is fixed
 * (PRD 7.8). The runner of the subscription's lane then hands the subscriber the aggregate once, at
 * its current version.
 *
 * Exit codes: 0 released (or already released), 64 invalid arguments, 65 subscription_unknown or
 * subscription_not_parked.
 */
#[Internal]
#[Description('Release an aggregate a subscription parked, so its runner handles it once at its current version')]
#[Signature('cms:events:release
        {subscription : The subscription, such as fragments.invalidate}
        {aggregate : The aggregate as <type>:<id>}')]
final class ReleaseParkedCommand extends Command
{
    public function handle(ReleaseParked $releases): int
    {
        try {
            $release = new ParkedRelease(
                SubscriptionArguments::subscription($this->argument('subscription')),
                SubscriptionArguments::aggregate($this->argument('aggregate')),
            );
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            $parked = $releases->release($release);
        } catch (ReleaseRefused $refused) {
            $this->error($refused->getMessage());

            return ErrorCode::from($refused->errorCode)->entry()->exit->value;
        }

        $this->info(sprintf(
            'Released %s for %s, parked after %d tries; its runner handles it once at its current version.',
            $parked->aggregate->toString(),
            $parked->subscription->value,
            $parked->attempts,
        ));

        return self::SUCCESS;
    }
}
