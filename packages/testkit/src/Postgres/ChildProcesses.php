<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ChildPayload;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use Symfony\Component\Process\Process;

/**
 * Runs a closure or a script in a separate PHP process against its own connection.
 *
 * For scenarios where one caller blocks while another holds a lock: an in-process connection
 * would block the test itself. The child gets a ProcessContext with a connection built from
 * the parent's connection settings, so it reaches the same database as the same role.
 *
 *     $child = $processes->start(static function (ProcessContext $context): void {
 *         $db = $context->connection();
 *         $db->beginTransaction();
 *         $db->select('select pg_advisory_xact_lock(1)');
 *         $context->signal('locked');
 *         $db->select('select pg_sleep(0.5)');
 *         $db->commit();
 *     });
 *     $child->waitForSignal('locked');
 *     // ... the parent now blocks on the lock ...
 *     $child->wait();
 *
 * A closure is serialised with laravel/serializable-closure, so what it captures must be
 * serialisable, and it may not use $this, self or static. A script is a PHP file that returns
 * such a closure.
 * The harness stops every child that still runs when the test ends.
 */
#[Experimental]
final class ChildProcesses
{
    /** @var list<ChildProcess> */
    private array $started = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    /**
     * @param  (Closure(ProcessContext): void)|string  $callback  a closure, or the path of a PHP script that returns one
     * @param  string|null  $connection  the connection whose settings the child uses; the default connection when null
     */
    public function start(Closure|string $callback, ?string $connection = null): ChildProcess
    {
        $settings = ConnectionSettings::of($connection ?? $this->database->getDefaultConnection(), $this->config);

        if (is_string($callback)) {
            $script = realpath($callback);

            if ($script === false || ! is_file($script)) {
                throw new InvalidArgumentException(sprintf('The child script [%s] does not exist.', $callback));
            }

            $payload = new ChildPayload($settings, script: $script);
        } else {
            // Unsigned: the payload goes to the child over stdin, and the child has no application
            // key to check a signature with. Laravel signs a SerializableClosure whenever app.key is set.
            $payload = new ChildPayload($settings, closure: serialize(new UnsignedSerializableClosure($this->unbound($callback))));
        }

        $process = new Process([PHP_BINARY, self::entryScript(), self::autoloader()]);
        $process->setInput($payload->encode());
        $process->setTimeout(null);
        $process->start();

        $child = new ChildProcess($process);
        $this->started[] = $child;

        return $child;
    }

    /**
     * Stops every child that still runs. The harness calls it after each test.
     */
    public function stopAll(): void
    {
        foreach ($this->started as $child) {
            $child->stop();
        }

        $this->started = [];
    }

    /**
     * Removes the closure's scope. A closure written in a Pest file is scoped to the class Pest
     * generates for the file, which does not exist in the child, and binding to it there fails.
     *
     * @param  Closure(ProcessContext): void  $callback
     * @return Closure(ProcessContext): void
     */
    private function unbound(Closure $callback): Closure
    {
        $reflection = new ReflectionClosure($callback);

        if ($reflection->isBindingRequired() || $reflection->isScopeRequired()) {
            throw new InvalidArgumentException(
                'A child process closure cannot use $this, self, static or parent: they do not exist in the child. Pass what it needs through use().',
            );
        }

        $unbound = Closure::bind($callback, null, null);

        if (! $unbound instanceof Closure) {
            throw new InvalidArgumentException('The child process closure could not be unbound from its scope.');
        }

        return $unbound;
    }

    public static function entryScript(): string
    {
        return dirname(__DIR__, 2).'/bin/postgres-child.php';
    }

    /**
     * The Composer autoloader that loaded the testkit, so the child loads the same classes.
     */
    public static function autoloader(): string
    {
        return CheckoutRoot::vendorDirectory().'/autoload.php';
    }
}
