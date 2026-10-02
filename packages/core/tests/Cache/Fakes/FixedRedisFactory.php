<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Cache\Fakes;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Override;
use UnitEnum;

/**
 * A Redis factory that gives one connection for every name and records the names it was asked.
 */
final class FixedRedisFactory implements Factory
{
    /** @var list<UnitEnum|string|null> */
    public array $names = [];

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param  UnitEnum|string|null  $name
     */
    #[Override]
    public function connection($name = null): Connection
    {
        $this->names[] = $name;

        return $this->connection;
    }
}
