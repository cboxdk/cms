<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\HookJson;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;

/*
 * A hook as cms:actions and cms:hooks show it: the addon and what it reads only for an addon's
 * hook, null in the JSON and left out of the line for any other.
 */

function shownHook(?AddonNamespace $addon = null, ?ClassificationAccess $reads = null): HookEntry
{
    return new HookEntry('Acme\Hooks\Slugs', 'acme/hooks', new CommandName('probe.rename'), 1, RenameProbe::class, Phase::Transform, 5, 2, $addon, $reads);
}

it('writes an addon\'s hook with its addon and what it reads, and another hook with null for both', function (): void {
    expect(HookJson::toArray(shownHook()))->toBe([
        'addon' => null,
        'budget_ms' => 2,
        'class' => 'Acme\Hooks\Slugs',
        'package' => 'acme/hooks',
        'phase' => 'transform',
        'priority' => 5,
        'reads' => null,
    ])
        ->and(HookJson::toArray(shownHook(new AddonNamespace('reviews'), ClassificationAccess::Internal)))->toMatchArray(['addon' => 'reviews', 'reads' => 'internal']);
});

it('names an addon and what it reads on the line of an addon\'s hook, which has both', function (?AddonNamespace $addon, ?ClassificationAccess $reads, string $line): void {
    expect(HookJson::line(shownHook($addon, $reads)))->toBe($line);
})->with([
    'no addon' => [null, null, 'transform  priority 5  budget 2 ms  Acme\Hooks\Slugs (acme/hooks)'],
    'an addon and its reads' => [new AddonNamespace('reviews'), ClassificationAccess::Internal, 'transform  priority 5  budget 2 ms  Acme\Hooks\Slugs (acme/hooks, addon reviews reading up to internal)'],
]);
