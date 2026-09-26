<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Core\CoreServiceProvider;
use Illuminate\Contracts\Console\Kernel;

it('registers the command that the core schedules', function (): void {
    $commands = app(Kernel::class)->all();

    expect($commands)->toHaveKey(CoreServiceProvider::PARTITIONS_COMMAND)
        ->and($commands[CoreServiceProvider::PARTITIONS_COMMAND])->toBeInstanceOf(MaintainPartitionsCommand::class);
});
