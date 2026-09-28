<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A migration is production code in the global namespace: it runs on every installation.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('things')->insert([
            'id' => Str::uuid()->toString(),
            'created_at' => now(),
        ]);
    }
};
