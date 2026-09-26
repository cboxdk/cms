<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use Closure;
use LogicException;
use RuntimeException;
use SplFileObject;

/**
 * Keeps the JS tool runs of the tests in one checkout apart from the probe files they write.
 *
 * tsc type checks the whole project, so a probe below Node::PROBE_DIRECTORY is part of every
 * `npm run typecheck` in the checkout while it exists. Under a parallel Pest run, the mutation run
 * of gate 5 among them, a test that must pass the type check then saw another process's probe that
 * is meant to fail it. A probe is therefore written, used and deleted under an exclusive lock, and
 * every other tool run holds a shared one, so a run sees the probes of its own callback and no
 * others. The lock is a file in the checkout's git-ignored .cache/, because each checkout has its
 * own probe directory. It is reentrant within a process: a tool run inside a probe's callback
 * runs under the probe's lock. The operating system releases it when a process exits.
 */
final class JsToolchainLock
{
    public const string PATH = '.cache/node/toolchain.lock';

    /**
     * Whether this process holds the lock exclusively, or null when it does not hold it.
     */
    private static ?bool $exclusiveHeld = null;

    /**
     * Runs the callback while this process holds the lock exclusively, waiting for every other
     * holder first.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function exclusive(Closure $callback): mixed
    {
        return self::hold(true, $callback);
    }

    /**
     * Runs the callback while this process holds the lock, shared with other shared holders.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function shared(Closure $callback): mixed
    {
        return self::hold(false, $callback);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private static function hold(bool $exclusive, Closure $callback): mixed
    {
        if (self::$exclusiveHeld === true || (self::$exclusiveHeld === false && ! $exclusive)) {
            return $callback();
        }

        if (self::$exclusiveHeld === false) {
            throw new LogicException('The JS toolchain lock is held shared and cannot be taken exclusively inside it.');
        }

        $path = Phpstan::root().'/'.self::PATH;

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o777, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Could not create '.dirname($path).'.');
        }

        $lock = new SplFileObject($path, 'c');

        if (! $lock->flock($exclusive ? LOCK_EX : LOCK_SH)) {
            throw new RuntimeException("Could not lock {$path}.");
        }

        self::$exclusiveHeld = $exclusive;

        try {
            return $callback();
        } finally {
            self::$exclusiveHeld = null;
            $lock->flock(LOCK_UN);
        }
    }
}
