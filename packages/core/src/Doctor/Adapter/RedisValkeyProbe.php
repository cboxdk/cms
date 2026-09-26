<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use LogicException;
use Override;
use Throwable;

/**
 * Pings Valkey on the configured Redis connection, through a Redis manager of its own whose
 * connection has connect and read timeouts of connect_timeout_seconds, so a server that does not
 * answer cannot hang the doctor.
 */
#[Internal]
final readonly class RedisValkeyProbe implements ValkeyProbe
{
    private const string REFUSED = '/WRONGPASS|NOAUTH|invalid password|invalid username-password|NOPERM/i';

    public function __construct(
        private Application $app,
        private Repository $config,
        private DoctorSettings $settings,
    ) {}

    #[Override]
    public function target(): string
    {
        $connection = $this->config->get('database.redis.'.$this->settings->redisConnection);

        if (! is_array($connection)) {
            return sprintf('the Redis connection %s, which is not configured', $this->settings->redisConnection);
        }

        if (is_string($connection['url'] ?? null) && $connection['url'] !== '') {
            $parts = parse_url($connection['url']);

            return sprintf('%s:%s (Redis connection %s)', $this->text($parts['host'] ?? null), $this->text($parts['port'] ?? null), $this->settings->redisConnection);
        }

        return sprintf('%s:%s (Redis connection %s)', $this->text($connection['host'] ?? null), $this->text($connection['port'] ?? null), $this->settings->redisConnection);
    }

    #[Override]
    public function ping(): void
    {
        $redis = $this->config->get('database.redis');
        $name = $this->settings->redisConnection;
        $connection = is_array($redis) ? ($redis[$name] ?? null) : null;

        if (! is_array($redis) || ! is_array($connection)) {
            throw ProbeFailed::violation(sprintf('The Redis connection %s is not configured in config/database.php.', $name));
        }

        $connection['timeout'] = $this->settings->connectTimeoutSeconds;
        $connection['read_timeout'] = $this->settings->connectTimeoutSeconds;
        $client = is_string($redis['client'] ?? null) ? $redis['client'] : 'phpredis';
        $manager = new RedisManager($this->app, $client, ['options' => $redis['options'] ?? [], $name => $connection]);

        try {
            $manager->connection($name)->command('ping');
        } catch (LogicException $logic) {
            throw ProbeFailed::violation($this->oneLine($logic->getMessage()), $logic);
        } catch (Throwable $thrown) {
            $message = $this->oneLine($thrown->getMessage());

            throw preg_match(self::REFUSED, $message) === 1
                ? ProbeFailed::violation($message, $thrown)
                : ProbeFailed::unavailable($message, $thrown);
        } finally {
            $manager->purge($name);
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '?';
    }

    private function oneLine(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $message));

        return $line === '' ? 'The client gave no message.' : $line;
    }
}
