<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

/**
 * One container of the shared services as `docker compose ps --all --format json` lists it: its
 * service, its state (`running`, `exited`, ...), its health (`healthy`, `starting`, `unhealthy`,
 * or empty without a health check) and the networks it is attached to.
 */
final readonly class ServiceState
{
    /**
     * @param  list<string>  $networks
     */
    public function __construct(
        public string $service,
        public string $state,
        public string $health,
        public array $networks,
    ) {}

    public function ready(): bool
    {
        return $this->state === 'running' && ($this->health === '' || $this->health === 'healthy');
    }

    public function describe(): string
    {
        return $this->health === '' ? $this->state : "{$this->state}, {$this->health}";
    }
}
