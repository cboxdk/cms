<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;

return [
    /*
     * The implementation of each contract (GUARDRAILS 2.3). An application overrides an entry in
     * its own config/cms.php; the entries it leaves out keep these defaults. The class is resolved
     * from the container when the contract is first resolved, as a singleton.
     */
    'contracts' => [
        Clock::class => SystemClock::class,
        IdGenerator::class => SystemIdGenerator::class,
    ],
];
