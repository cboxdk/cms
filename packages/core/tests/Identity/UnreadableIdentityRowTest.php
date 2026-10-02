<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Core\Identity\Adapter\UnreadableIdentityRow;
use InvalidArgumentException;

/*
 * A row of an identity table that holds no valid identity value is refused with the value's own
 * reason, kept as the cause, and the exception code 0.
 */

it('names the table and the reason of a refused row, keeps the cause and has the exception code 0', function (): void {
    $cause = new InvalidArgumentException('not a UUIDv7');
    $refused = UnreadableIdentityRow::refused('actors', $cause);

    expect([$refused->getMessage(), $refused->getCode(), $refused->getPrevious()])
        ->toBe(['A row of actors is not a valid identity value: not a UUIDv7', 0, $cause]);
});
