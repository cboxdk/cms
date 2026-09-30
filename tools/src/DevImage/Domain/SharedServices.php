<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

use Cbox\Cms\Tooling\Services\Domain\ServicesPlan;

/**
 * Whether the shared services of the main checkout's compose.yaml, Postgres and Valkey, run and
 * are healthy, and the network a container of the dev image joins to reach them by their
 * service names. The dev image never starts or recreates them: they are shared by the main
 * checkout and every worktree, and `composer services:up` in the main checkout starts them.
 */
final readonly class SharedServices
{
    private function __construct(
        public ?string $network,
        public ?string $problem,
    ) {}

    /**
     * @param  list<ServiceState>  $states  the containers `docker compose ps --all` lists
     */
    public static function of(array $states, string $mainRoot): self
    {
        $problems = [];
        $network = null;

        foreach (ServicesPlan::SHARED_SERVICES as $service) {
            $state = array_find($states, static fn (ServiceState $state): bool => $state->service === $service);

            if (! $state instanceof ServiceState) {
                $problems[] = "{$service} (not created)";
            } elseif (! $state->ready()) {
                $problems[] = "{$service} ({$state->describe()})";
            } elseif ($state->networks === []) {
                $problems[] = "{$service} (on no network)";
            } else {
                $network ??= $state->networks[0];
            }
        }

        if ($problems !== []) {
            return new self(null, self::fix('The shared services are not running: '.implode(', ', $problems).'.', $mainRoot));
        }

        return new self($network, null);
    }

    /**
     * The failure when docker compose cannot list the services, such as when Docker is not
     * running.
     */
    public static function unknown(string $reason, string $mainRoot): self
    {
        return new self(null, self::fix("Cannot list the shared services with docker compose: {$reason}", $mainRoot));
    }

    public function ready(): bool
    {
        return $this->problem === null && $this->network !== null;
    }

    private static function fix(string $problem, string $mainRoot): string
    {
        return $problem.' The dev image reaches Postgres and Valkey on the network of the main checkout\'s compose.yaml and never starts them. Start Docker if it is not running, then start the services from the main checkout: cd '.$mainRoot.' && composer services:up';
    }
}
