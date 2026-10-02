<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login\Fakes;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use DateInterval;
use DateTimeImmutable;
use Override;

/**
 * The login throttle in memory, held to ValkeyLoginThrottle by LoginThrottleBehaviour. A key's
 * count lives until its window, read from its Clock, has passed since the first attempt it counted,
 * as Valkey keeps the key until its TTL.
 */
final class FakeLoginThrottle implements LoginThrottle
{
    /** @var array<string, array{int, DateTimeImmutable}> the count and its end, by scope and key */
    private array $counts = [];

    public function __construct(
        private readonly LoginThrottleSettings $settings,
        private readonly Clock $clock,
    ) {}

    #[Override]
    public function hit(LoginThrottleKeys $keys): ?ThrottleScope
    {
        $over = null;

        foreach ([ThrottleScope::Identifier, ThrottleScope::Ip] as $scope) {
            $name = $this->name($scope, $keys);
            $limit = $this->settings->limit($scope);
            [$count, $end] = $this->live($name) ?? [0, $this->clock->now()->add(new DateInterval('PT'.$limit->windowSeconds.'S'))];
            $this->counts[$name] = [$count + 1, $end];

            if (! $over instanceof ThrottleScope && $count + 1 > $limit->attempts) {
                $over = $scope;
            }
        }

        return $over;
    }

    #[Override]
    public function succeeded(LoginThrottleKeys $keys): void
    {
        unset($this->counts[$this->name(ThrottleScope::Identifier, $keys)]);

        $ip = $this->name(ThrottleScope::Ip, $keys);
        $live = $this->live($ip);

        if ($live === null || $live[0] <= 1) {
            unset($this->counts[$ip]);

            return;
        }

        $this->counts[$ip] = [$live[0] - 1, $live[1]];
    }

    /**
     * The attempts counted under the key of the scope that are still in their window.
     */
    public function count(ThrottleScope $scope, LoginThrottleKeys $keys): int
    {
        return $this->live($this->name($scope, $keys))[0] ?? 0;
    }

    /**
     * @return array{int, DateTimeImmutable}|null
     */
    private function live(string $name): ?array
    {
        $count = $this->counts[$name] ?? null;

        if ($count === null || $count[1] <= $this->clock->now()) {
            unset($this->counts[$name]);

            return null;
        }

        return $count;
    }

    private function name(ThrottleScope $scope, LoginThrottleKeys $keys): string
    {
        return $scope->value.':'.$keys->key($scope);
    }
}
