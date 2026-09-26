<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\CoreServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

it('schedules cms:partitions:maintain every hour', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, CoreServiceProvider::PARTITIONS_COMMAND),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 * * * *');
});

it('lists the schedule entry in schedule:list', function (): void {
    $artisan = app(Kernel::class);

    expect($artisan->call('schedule:list'))->toBe(0)
        ->and($artisan->output())->toMatch('/0 \* \* \* \*\s+php artisan cms:partitions:maintain/');
});
