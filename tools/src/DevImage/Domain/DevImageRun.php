<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

/**
 * The `docker run` that runs a command for a checkout in the dev image, as `composer check`,
 * `composer test:affected` and `composer image:run` start it from the host:
 *
 * - the checkout is mounted at the same absolute path as on the host and is the working
 *   directory, because the testkit derives the checkout's own Postgres test database from that
 *   path (TestDatabaseName), so two worktrees never share one, and the gates report host paths;
 * - a linked worktree also gets the main checkout's .git at the same path, where its git
 *   directory lives, so git works in the container;
 * - node_modules, .cache and the Testbench application's bootstrap cache are Docker volumes of the
 *   checkout's own (CheckoutVolume, VolumeKind), and the host's bootstrap cache is mounted read-only
 *   at HOST_BOOTSTRAP_CACHE for the entry to mirror;
 * - the host's ~/.pest is HOME/.pest, where Pest keeps the graph of test impact analysis, so the
 *   host and every run share it;
 * - it runs as the host user's uid and gid, never as root, because root ignores file permissions
 *   and the tests of unwritable files would skip, and with the host's name, which the testkit
 *   writes into the comment of each test database for `composer test-db:prune`;
 * - it joins the network of the shared services and reaches them by their service names, with
 *   the variables compose.yaml's php service sets, which win over phpunit.xml's host ports;
 * - the image's entry point is replaced, because it runs chown -R on /var/www/html, and tini
 *   (--init) passes signals on, so Ctrl-C stops the command;
 * - the command starts through tools/bin/dev-image-entry.php, which first brings the node_modules
 *   volume to package-lock.json and mirrors the host's bootstrap cache into its volume.
 *
 * Xdebug stays off; `composer test:affected` turns PCOV on for its run with tools/tia/pcov.ini.
 */
final readonly class DevImageRun
{
    public const string ENTRY = 'tools/bin/dev-image-entry.php';

    /**
     * The host variables a run passes on when they are set: the terminal type, and the base of
     * the change for mutation on changed files in `composer check -- --pr`.
     *
     * @var list<string>
     */
    public const array PASSED_VARIABLES = ['TERM', 'CMS_CI_BASE_REF'];

    /**
     * The variables of compose.yaml's php service: the services by name and container port.
     *
     * @var array<string, string>
     */
    public const array SERVICE_ENVIRONMENT = [
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'REDIS_HOST' => 'valkey',
        'REDIS_PORT' => '6379',
    ];

    /**
     * Where the host's bootstrap cache of the Testbench application is mounted read-only, and the
     * variable that tells the entry to mirror it into the checkout's bootstrap cache volume.
     */
    public const string HOST_BOOTSTRAP_CACHE = '/cms-host/bootstrap-cache';

    public const string HOST_BOOTSTRAP_CACHE_VARIABLE = 'CMS_HOST_BOOTSTRAP_CACHE';

    /**
     * @return list<string>
     */
    public static function command(DevImageTarget $target): array
    {
        $root = $target->checkout->root;
        $mounts = [$root.':'.$root];
        $gitDirectory = $target->checkout->mainRoot.'/.git';

        if (! str_starts_with($gitDirectory.'/', $root.'/')) {
            $mounts[] = $gitDirectory.':'.$gitDirectory;
        }

        foreach (CheckoutVolume::all($root) as $volume) {
            $mounts[] = $volume->name.':'.$volume->mountPoint();
        }

        $mounts[] = $root.'/'.VolumeKind::BootstrapCache->directory().':'.self::HOST_BOOTSTRAP_CACHE.':ro';
        $mounts[] = $target->pestDirectory.':'.DevImage::HOME.'/.pest';

        foreach ($target->extraMounts as $directory) {
            if (! str_starts_with($directory.'/', $root.'/')) {
                $mounts[] = $directory.':'.$directory;
            }
        }

        $environment = [
            'HOME' => DevImage::HOME,
            ...self::SERVICE_ENVIRONMENT,
            'XDEBUG_MODE' => 'off',
            self::HOST_BOOTSTRAP_CACHE_VARIABLE => self::HOST_BOOTSTRAP_CACHE,
        ];

        foreach (self::PASSED_VARIABLES as $variable) {
            if (isset($target->passedEnvironment[$variable])) {
                $environment[$variable] = $target->passedEnvironment[$variable];
            }
        }

        $options = [];

        foreach ($mounts as $mount) {
            $options[] = '--volume';
            $options[] = $mount;
        }

        foreach ($environment as $name => $value) {
            $options[] = '--env';
            $options[] = $name.'='.$value;
        }

        return [
            'docker', 'run', '--rm', '--init',
            ...($target->interactive ? ['--interactive', '--tty'] : []),
            '--entrypoint', '',
            '--user', $target->user->uid.':'.$target->user->gid,
            '--hostname', $target->hostname,
            '--network', $target->network,
            '--workdir', $root,
            ...$options,
            DevImage::IMAGE,
            'php', self::ENTRY,
            ...$target->command,
        ];
    }

    /**
     * The one-off root container that hands new volumes to the host user, because Docker creates a
     * volume's root directory owned by root.
     *
     * @param  non-empty-list<CheckoutVolume>  $volumes
     * @return list<string>
     */
    public static function claimVolumes(array $volumes, DevImageTarget $target): array
    {
        $options = [];
        $directories = [];

        foreach ($volumes as $volume) {
            $options[] = '--volume';
            $options[] = $volume->name.':/volumes/'.$volume->kind->value;
            $directories[] = '/volumes/'.$volume->kind->value;
        }

        return [
            'docker', 'run', '--rm', '--entrypoint', '', '--user', '0:0',
            ...$options,
            DevImage::IMAGE,
            'chown', $target->user->uid.':'.$target->user->gid, ...$directories,
        ];
    }
}
