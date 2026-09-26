<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Postgres\Boundary\ChildPayload;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use Throwable;

/**
 * The body of a child process started by ChildProcesses (bin/postgres-child.php).
 *
 * It reads the payload from standard input, opens its own connection with Laravel's database
 * component, installs the nested transaction guard on it, and calls the closure or script with a
 * ProcessContext. It boots no application, so a child starts in a fraction of a second.
 *
 * Exit codes: 0 when the callback returned, 1 when it threw, 2 when the payload was invalid.
 */
#[Internal]
final class ChildProcessMain
{
    public const int OK = 0;

    public const int FAILED = 1;

    public const int INVALID = 2;

    /**
     * @param  Closure(string): void  $stdout
     * @param  Closure(string): void  $stderr
     */
    public static function run(string $input, Closure $stdout, Closure $stderr): int
    {
        try {
            $payload = ChildPayload::decode($input);
            $callback = self::callback($payload);
        } catch (Throwable $exception) {
            $stderr(self::describe($exception));

            return self::INVALID;
        }

        try {
            $connection = self::connect($payload);
            $callback(new ProcessContext($connection, $stdout));
            $connection->disconnect();
        } catch (Throwable $exception) {
            $stderr(self::describe($exception));

            return self::FAILED;
        }

        return self::OK;
    }

    /**
     * @return Closure(ProcessContext): void
     */
    private static function callback(ChildPayload $payload): Closure
    {
        if ($payload->closure !== null) {
            $wrapper = unserialize($payload->closure);

            if (! $wrapper instanceof UnsignedSerializableClosure) {
                throw new InvalidArgumentException('The child payload does not hold a serialised closure.');
            }

            return $wrapper->getClosure();
        }

        $script = (string) $payload->script;

        if (! is_file($script)) {
            throw new InvalidArgumentException(sprintf('The child script [%s] does not exist.', $script));
        }

        $callback = require $script;

        if (! $callback instanceof Closure) {
            throw new InvalidArgumentException(sprintf('The child script [%s] does not return a closure.', $script));
        }

        return $callback;
    }

    private static function connect(ChildPayload $payload): PostgresConnection
    {
        $events = new Dispatcher(new Container);
        (new NestedTransactionGuard)->install($events);

        $capsule = new Manager;
        $capsule->addConnection($payload->connection->toConfig(), $payload->connection->name);
        $capsule->setEventDispatcher($events);

        $connection = $capsule->getConnection($payload->connection->name);

        if (! $connection instanceof PostgresConnection) {
            throw new InvalidArgumentException('The child connection is not a Postgres connection.');
        }

        $connection->getPdo();

        return $connection;
    }

    private static function describe(Throwable $exception): string
    {
        $lines = [];

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $lines[] = sprintf('%s: %s', $current::class, $current->getMessage());
        }

        return implode("\nCaused by ", $lines)."\n".$exception->getTraceAsString()."\n";
    }
}
