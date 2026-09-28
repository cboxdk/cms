<?php

declare(strict_types=1);

use Illuminate\Support\Str;

// A Pest file: the global namespace below a tests directory is test code, and anywhere else it is not.
it('stamps a thing', function (): void {
    expect(now())->not->toBeNull()
        ->and(Str::uuid()->toString())->toBeString();
});
