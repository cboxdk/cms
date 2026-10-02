<?php

declare(strict_types=1);

// Every name HookIoRule lists, each reached the way the rule reads it: a class name as a string the
// container resolves, a container id, a function and a function family, a callable, a variable
// function, a type in a signature and a property, instanceof, a constant and a static property.

namespace Fixture\HookIoNames {
    use Cbox\Cms\Contracts\Hooks\HookErrors;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\ValidateHook;
    use Illuminate\Contracts\Container\Container;
    use Psr\Container\ContainerInterface;

    final class NamesEverything implements ValidateHook
    {
        public ?\PDO $pdo = null;

        public ?\Redis $redis = null;

        public function __construct(private Container $container, private ContainerInterface $psr) {}

        public function validate(PlanView $plan): HookErrors
        {
            app('Doctrine\DBAL\Connection');
            app('Illuminate\Cache\Repository');
            app('Illuminate\Contracts\Cache\Repository');
            app('Illuminate\Contracts\Database\Query\Builder');
            app('Illuminate\Contracts\Redis\Factory');
            app('Illuminate\Database\Connection');
            app('Illuminate\Redis\RedisManager');
            app('Illuminate\Support\Facades\Cache');
            app('Illuminate\Support\Facades\DB');
            app('Illuminate\Support\Facades\Redis');
            app('Illuminate\Support\Facades\Schema');
            app('Memcached');
            app('mysqli');
            app('PDO');
            app('PDOStatement');
            app('Predis\Client');
            app('Redis');
            app('RedisCluster');
            app('SQLite3');
            app('\PDO');
            app('Illuminate\Support\Facades\DB::table');
            app('cache');
            app('cache.store');
            app('db');
            app('db.connection');
            app('db.factory');
            app('db.schema');
            app('redis');
            app('redis.connection');
            resolve('db');
            $this->container->get('db');
            $this->container->make('redis');
            $this->container->makeWith('cache', []);
            $this->container->offsetGet('db.schema');
            $this->psr->get('redis');
            $this->container['db'];
            apcu_fetch('key');
            mysqli_connect();
            pg_query('select 1');
            sqlite_open('file');
            \PG_QUERY('select 1');
            array_map('pg_escape_string', []);
            $query = 'pg_query';
            $query('select 1');
            $this->redis?->get('key');
            $read = app(...);
            $upper = '\PG_QUERY';
            $upper('select 1');
            app(random_int(0, 1) === 0 ? 'db' : 'mysqli');
            app('files');
            file_get_contents('/tmp/x');
            app();
            $method = 'get';
            $this->container->$method('db');
            $this->container[] = 'value';
            $made = \PDO::class;
            new $made('sqlite::memory:');

            return $this->redis instanceof \Redis || \PDO::ATTR_ERRMODE === 0 || $read === null ? HookErrors::none() : HookErrors::none();
        }

        public function store(\PDO $pdo): ?\SQLite3
        {
            return $pdo === null ? null : null;
        }
    }
}
