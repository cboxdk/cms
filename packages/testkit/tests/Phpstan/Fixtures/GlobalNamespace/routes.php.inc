<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// A route file is production code in the global namespace: it runs on every request.
Route::get('/stamp', static fn (): array => [
    'at' => time(),
    'request' => (string) Str::ulid(),
]);
