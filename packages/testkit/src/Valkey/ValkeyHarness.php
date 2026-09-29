<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Valkey\Boundary\ValkeySettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * One test against real Valkey, from set-up to tear-down (GUARDRAILS 9).
 *
 * Set-up points every configured Redis connection at the test database index and the run's key
 * prefix, fails fast when Valkey is down, and makes the application build its Redis manager from
 * that configuration. The test then uses Laravel's Redis connections as usual, and every key it
 * writes lands under the prefix. Tear-down disconnects the clients and removes the keys under the
 * prefix with SCAN and UNLINK.
 *
 * Tests reach the run through the container: `app(ValkeyRun::class)`.
 */
#[Experimental]
final readonly class ValkeyHarness
{
    /**
     * Keys of `database.redis` that are not connections.
     *
     * @var list<string>
     */
    public const array NOT_CONNECTIONS = ['client', 'options', 'clusters'];

    private function __construct(
        private Application $app,
        private ValkeyRun $run,
    ) {}

    /**
     * @param  string  $connection  the Redis connection the service check and the clean-up use
     */
    public static function start(?Application $app, string $connection = 'default'): self
    {
        if (! $app instanceof Application) {
            throw new LogicException('The Valkey harness starts after the application has booted.');
        }

        $config = $app->make(Repository::class);
        self::configure($config, 'database', ValkeyRun::DATABASE);

        $settings = ValkeySettings::of($connection, $config);
        ValkeyServiceCheck::ensureReachable($settings);

        $run = ValkeyRun::forProcess($settings);
        self::configure($config, 'prefix', $run->prefix);
        $config->set('database.redis.options.prefix', $run->prefix);

        // The Redis manager copies the configuration when it is built, so drop one that was built
        // during boot. The next use builds it from the configuration above.
        $app->forgetInstance('redis');
        Facade::clearResolvedInstance('redis');

        $app->instance(ValkeyRun::class, $run);

        return new self($app, $run);
    }

    public function finish(): void
    {
        if ($this->app->resolved('redis')) {
            $manager = $this->app->make(RedisManager::class);

            // By the configured names: the manager's own list of connections is null until it has
            // opened one, whatever its PHPDoc says.
            foreach (self::connectionNames($this->app->make(Repository::class)) as $name) {
                $manager->purge($name);
            }
        }

        $this->run->clean();
    }

    /**
     * Sets $key on every configured Redis connection.
     */
    private static function configure(Repository $config, string $key, int|string $value): void
    {
        foreach (self::connectionNames($config) as $name) {
            $config->set(sprintf('database.redis.%s.%s', $name, $key), $value);
        }
    }

    /**
     * The names of the configured Redis connections.
     *
     * @return list<string>
     */
    private static function connectionNames(Repository $config): array
    {
        $redis = $config->get('database.redis');

        if (! is_array($redis)) {
            throw new LogicException('There is no Redis configuration in database.redis.');
        }

        $names = [];

        foreach ($redis as $name => $settings) {
            if (is_string($name) && is_array($settings) && ! in_array($name, self::NOT_CONNECTIONS, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
