<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser;

use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The Browser suite runs on the testkit's harnesses, as the Postgres suite does (tests/Pest.php):
 * the application the browser plugin serves in this process reads this checkout's own migrated
 * test database, or in a parallel run the worker's own, as the app role, and Valkey on the test
 * index under this process's key prefix. A page the browser loads shows what it read, so the
 * proof goes through the browser and the served application, not around them.
 */

it('serves pages from this checkout\'s or worker\'s own migrated test database as the app role, and from Valkey under the run\'s prefix', function (): void {
    Route::get('/_probe/harness', static function (): string {
        $database = DB::selectOne("select current_database() as name, current_user as role, to_regclass('actors') is not null as migrated");
        app(RedisManager::class)->connection()->set('browser-probe', 'stored by the served application');
        $stored = app(RedisManager::class)->connection()->get('browser-probe');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Harness</title><link rel="icon" href="data:,"></head><body><main>'
            .'<p id="database">'.e(is_object($database) && is_string($database->name ?? null) ? $database->name : '').'</p>'
            .'<p id="role">'.e(is_object($database) && is_string($database->role ?? null) ? $database->role : '').'</p>'
            .'<p id="migrated">'.(is_object($database) && ($database->migrated ?? false) === true ? 'migrated' : 'not migrated').'</p>'
            .'<p id="valkey">'.e(is_string($stored) ? $stored : '').'</p>'
            .'</main></body></html>';
    });

    $page = visit('/_probe/harness');

    expect($page->text('#database'))->toBe(CheckoutDatabase::name())
        ->and($page->text('#role'))->toBe(config('database.connections.pgsql.username'))
        ->and($page->text('#migrated'))->toBe('migrated')
        ->and($page->text('#valkey'))->toBe('stored by the served application')
        ->and(app(ValkeyRun::class)->keys())->toContain(app(ValkeyRun::class)->prefix.'browser-probe');

    $page->assertNoConsoleLogs()->assertNoJavaScriptErrors();
});
