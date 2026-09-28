<?php

declare(strict_types=1);

use Illuminate\Support\Str;

// A config file is production code in the global namespace: it is read on every boot.
return [
    'booted_at' => new DateTimeImmutable(),
    'instance' => Str::orderedUuid()->toString(),
];
