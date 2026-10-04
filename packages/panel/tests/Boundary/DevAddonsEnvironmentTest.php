<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use InvalidArgumentException;

/*
 * CBOX_CMS_PANEL_DEV_ADDONS (PRD 13.4): `<namespace>=<origin>` pairs joined by commas, each a
 * loopback origin over http, read from the process's environment; anything else is refused with
 * panel_dev_addons_invalid, naming the pair's position and never its value. A DevServer's
 * addresses are those the addon's build plugin serves and its import analysis writes, so the
 * constants are held equal to @cboxdk/cms-panel/vite's.
 */

it('reads pairs of a namespace and a loopback origin, sorted by namespace, and none when unset or empty', function (): void {
    $addons = DevAddonsEnvironment::parse(' tally=http://localhost:5174/ ,approvals=http://127.0.0.1:5175');

    expect(array_map(static fn (DevServer $server): string => $server->addon->value.' '.$server->origin, $addons->servers))
        ->toBe(['approvals http://127.0.0.1:5175', 'tally http://localhost:5174'])
        ->and($addons->of(new AddonNamespace('tally'))?->origin)->toBe('http://localhost:5174')
        ->and($addons->of(new AddonNamespace('other')))->toBeNull()
        ->and(DevAddonsEnvironment::parse(null)->any())->toBeFalse()
        ->and(DevAddonsEnvironment::parse('  ')->any())->toBeFalse();
});

it('refuses text that is not such pairs, naming what is wrong and not the value', function (string $value, string $reason): void {
    expect(fn (): DevAddons => DevAddonsEnvironment::parse($value))->toThrow(InvalidDevAddons::class, $reason);

    try {
        DevAddonsEnvironment::parse($value);
    } catch (InvalidDevAddons $invalid) {
        expect($invalid->getMessage())->not->toContain('example.test')->and(InvalidDevAddons::CODE)->toBe('panel_dev_addons_invalid');
    }
})->with([
    'no equals sign' => ['tally', 'pair 1 is not <namespace>=<origin>'],
    'a namespace that is none' => ['Tally=http://localhost:5174', 'pair 1 does not start with an addon namespace'],
    'a reserved namespace' => ['app=http://localhost:5174', 'pair 1 does not start with an addon namespace'],
    'another host' => ['tally=https://cdn.example.test', 'the origin of addon tally in pair 1 is not a loopback origin over http'],
    'a path on the origin' => ['tally=http://localhost:5174/src', 'the origin of addon tally in pair 1 is not a loopback origin over http'],
    'https on localhost' => ['tally=https://localhost:5174', 'the origin of addon tally in pair 1 is not a loopback origin over http'],
    'an addon twice' => ['tally=http://localhost:5174,tally=http://localhost:5175', 'The addon tally has two dev servers.'],
]);

it('reads the variable from the environment of the process', function (): void {
    expect(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174'], static fn (): array => DevAddonsEnvironment::read()->servers))->toHaveCount(1)
        ->and(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174'], static fn (): ?string => DevAddonsEnvironment::raw()))->toBe('tally=http://localhost:5174')
        ->and(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => null], static fn (): ?string => DevAddonsEnvironment::raw()))->toBeNull()
        ->and(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => ''], static fn (): ?string => DevAddonsEnvironment::raw()))->toBeNull();
});

it('gives the addresses the addon\'s build plugin serves and its import analysis writes', function (): void {
    $server = new DevServer(new AddonNamespace('tally'), 'http://localhost:5174');
    $plugin = (string) file_get_contents(Codebase::root().'/js/panel-sdk/vite.js');

    expect($server->entryUrl())->toBe('http://localhost:5174/@cms-panel-addon/entry')
        ->and($server->sharedUrl('react/jsx-runtime'))->toBe('http://localhost:5174/@id/react/jsx-runtime')
        ->and($server->clientUrl())->toBe('http://localhost:5174/@vite/client')
        ->and($server->websocket())->toBe('ws://localhost:5174')
        ->and($plugin)->toContain("export const DEV_ENTRY = '".DevServer::ENTRY."';")
        ->and($plugin)->toContain("export const DEV_SHARED_PREFIX = '".DevServer::SHARED_PREFIX."';")
        ->and(fn (): DevServer => new DevServer(new AddonNamespace('tally'), 'http://localhost:5174/'))->toThrow(InvalidArgumentException::class);
});
