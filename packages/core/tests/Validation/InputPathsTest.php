<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Validation;

use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Cbox\Cms\Core\Validation\Boundary\InputPaths;

/*
 * The paths and keys the input validator names: a path at the top of the input is the segment
 * alone, a name, and a key is shown quoted, in full up to 64 bytes and cut after that.
 */

it('names a member at the top of the input by its key, refuses an index there as a path name, and names a segment below a path after it', function (): void {
    expect(static fn (): FieldPath => InputPaths::below(null, 3))->toThrow(InvalidWriteResult::class, 'A field path name is a letter or an underscore followed by letters, digits and underscores, got "3".')
        ->and(InputPaths::below(null, 'title')->segments)->toBe(['title'])
        ->and(InputPaths::below(new FieldPath('items'), 3)->segments)->toBe(['items', 3]);
});

it('shows a key of 64 bytes in full and cuts a longer one after 64, an integer key as its digits', function (): void {
    $full = str_repeat('k', 64);

    expect(InputPaths::shown($full))->toBe("\"{$full}\"")
        ->and(InputPaths::shown($full.'x'))->toBe("\"{$full}...\"")
        ->and(InputPaths::shown(7))->toBe('"7"');
});
