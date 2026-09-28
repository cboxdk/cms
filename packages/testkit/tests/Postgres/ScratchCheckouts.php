<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;

final class ScratchCheckouts
{
    /** @var list<string> */
    public static array $roots = [];

    /**
     * The parallel workers whose databases a test provisioned for its scratch checkouts.
     *
     * @var list<int>
     */
    public static array $workers = [];

    public static function make(): string
    {
        $root = ScratchDirectory::make('cbox-cms-test-database-');
        self::$roots[] = $root;

        return $root;
    }
}
