<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Closure;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;

/**
 * What a closure or script gets inside a child process: its own connection, and a way to tell
 * the parent test that it has reached a point, for example that it now holds a lock.
 */
#[Experimental]
final readonly class ProcessContext
{
    /** The line prefix that marks a signal on the child's standard output. */
    public const string SIGNAL_PREFIX = '@cms-testkit-signal ';

    /**
     * @param  Closure(string): void  $write  writes a line to the child's standard output
     */
    public function __construct(
        private PostgresConnection $connection,
        private Closure $write,
    ) {}

    /**
     * The child's own connection. It is a separate Postgres backend from every connection in
     * the parent, and nesting transactions on it fails like it does in the parent.
     */
    public function connection(): PostgresConnection
    {
        return $this->connection;
    }

    /**
     * Tells the parent that the child has reached $marker. The parent waits for it with
     * ChildProcess::waitForSignal().
     */
    public function signal(string $marker): void
    {
        if ($marker === '' || preg_match('/[\r\n]/', $marker) === 1) {
            throw new InvalidArgumentException('A signal is one non-empty line.');
        }

        ($this->write)(self::SIGNAL_PREFIX.$marker."\n");
    }
}
