<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/**
 * The tool scripts that run DROP DATABASE on the shared test server, as the Postgres tests start
 * them: tools/bin/drop-test-database.php and tools/bin/prune-test-databases.php.
 *
 * DROP DATABASE asks for a forced checkpoint of the whole server and waits for it (dropdb() in
 * Postgres' dbcommands.c), so that the checkpointer forgets the database's files before they are
 * removed. That checkpoint syncs every file any database changed since the last one. On the
 * server every checkout shares, the Postgres suites of the other checkouts change up to hundreds
 * of thousands of files between two checkpoints, because truncations and partitions give tables
 * new files, and the server logged forced checkpoints of up to 91 s, nearly all of it spent in
 * sync. A drop that asks while one runs waits for it and then for its own. Symfony's default
 * timeout of 60 s is shorter than one such checkpoint, so a drop that worked was killed as a
 * timeout (M0-R1-8). The timeout still ends a script that hangs.
 */
final class DropDatabaseScripts
{
    /** The longest forced checkpoint the shared test server logged, 91 s, rounded up. */
    public const int LONGEST_FORCED_CHECKPOINT_SECONDS = 100;

    /** Two forced checkpoints, the running one and the drop's own, with room for the script. */
    public const int TIMEOUT_SECONDS = 300;

    /**
     * The script $command, run from the repository root with $environment over the inherited one.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public static function process(array $command, array $environment = []): Process
    {
        return new Process($command, Phpstan::root(), $environment, null, self::TIMEOUT_SECONDS);
    }
}
