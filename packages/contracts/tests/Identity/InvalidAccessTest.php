<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\InvalidAccess;
use Cbox\Cms\Contracts\Identity\NodePath;

/*
 * The refusals of node paths, access regions and access contexts, each with its own message; a
 * refused path is shown escaped and cut after 64 bytes.
 */

it('shows a refused path escaped, and cut after 64 bytes', function (): void {
    $long = str_repeat('a', 64);

    expect(InvalidAccess::path("root.\"x\"\\\n\xff")->getMessage())->toBe('A node path is labels of 1 to 1000 letters, digits, underscores and hyphens joined by dots, got "root.\\"x\\"\\\\\\n\\377".')
        ->and(InvalidAccess::path($long)->getMessage())->toEndWith('got "'.$long.'".')
        ->and(InvalidAccess::path($long.'b')->getMessage())->toEndWith('got "'.$long.'...".')
        ->and(InvalidAccess::path('')->getCode())->toBe(0);
});

it('names the paths of a region or context that breaks its rules, and the ceiling', function (): void {
    $root = new NodePath('root');
    $news = new NodePath('root.news');

    expect(InvalidAccess::exceptionOutside($news, $root)->getMessage())->toBe('An exception of an access region lies below its path root.news, but root does not.')
        ->and(InvalidAccess::nestedExceptions($root, $news)->getMessage())->toBe('The exceptions of an access region are disjoint, but root.news is repeated in or below root.')
        ->and(InvalidAccess::overlappingRegions($root, $news)->getMessage())->toBe('The access regions of a context are disjoint, but root.news is at or below root and in none of its exceptions.')
        ->and(InvalidAccess::aboveCeiling(ClassificationAccess::Personal, ClassificationAccess::Internal)->getMessage())->toBe('The classification access of a context never exceeds its principal\'s ceiling, internal, got personal.');
});
