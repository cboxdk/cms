<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

/*
 * The Valkey service in compose.yaml, reached through Laravel's redis connection.
 */

it('reaches Valkey through the default redis connection', function (): void {
    $connection = Redis::connection();

    expect($connection->command('ping'))->toBeTrue()
        ->and($connection->command('info', ['server']))->toHaveKey('valkey_version');
});
