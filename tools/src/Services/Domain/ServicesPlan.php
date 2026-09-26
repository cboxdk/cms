<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Domain;

/**
 * The docker compose commands of `composer services:up` and `services:down` for a checkout, run
 * in order until one fails, with the environment they run in and what to print once they all
 * passed, or the refusal that runs nothing.
 *
 * Every command names the main checkout's compose.yaml and the main checkout as the project
 * directory, so the bind mounts of the services are always the main checkout's paths, whichever
 * checkout runs the script. From the main checkout, up starts all services and waits until they
 * are healthy, then runs the idempotent init script, so an existing Postgres volume gets the
 * current roles and databases; down stops them and keeps the volumes. From a linked worktree the
 * services are shared with the main checkout and every other worktree: up starts only Postgres
 * and Valkey, never recreates a container and never starts php, whose bind mount is the main
 * checkout, and down refuses.
 */
final readonly class ServicesPlan
{
    public const string COMPOSE_FILE = 'compose.yaml';

    public const string INIT_SCRIPT = '/docker-entrypoint-initdb.d/10-cms.sh';

    /** The services a linked worktree may start: the shared ones, without php. */
    public const array SHARED_SERVICES = ['postgres', 'valkey'];

    /**
     * @param  list<list<string>>  $commands
     * @param  array<string, string>  $environment
     * @param  list<string>  $notes
     */
    private function __construct(
        public array $commands,
        public array $environment,
        public array $notes,
        public ?string $refusal,
    ) {}

    public static function for(ServicesAction $action, Checkout $checkout, HostUser $user): self
    {
        $compose = self::compose($checkout);
        $environment = ['CMS_UID' => (string) $user->uid, 'CMS_GID' => (string) $user->gid];
        $init = [...$compose, 'exec', '-T', 'postgres', self::INIT_SCRIPT];

        if (! $checkout->isLinkedWorktree()) {
            return match ($action) {
                ServicesAction::Up => new self([[...$compose, 'up', '-d', '--wait'], $init], $environment, [], null),
                ServicesAction::Down => new self([[...$compose, 'down']], $environment, [], null),
            };
        }

        return match ($action) {
            ServicesAction::Up => new self(
                [[...$compose, 'up', '-d', '--wait', '--no-recreate', ...self::SHARED_SERVICES], $init],
                $environment,
                [
                    "{$checkout->root} is a linked worktree of {$checkout->mainRoot}. The services are shared: Postgres and Valkey run from {$checkout->mainRoot}/".self::COMPOSE_FILE.', no running container was recreated, and php was not started from here.',
                    "The php container mounts the main checkout, {$checkout->mainRoot}:/var/www/html, so `docker compose exec php ...` tests the main checkout's code, not this worktree's. Run container-bound work, such as mutation, from {$checkout->mainRoot}.",
                ],
                null,
            ),
            ServicesAction::Down => new self(
                [],
                $environment,
                [],
                "composer services:down does not run in the linked worktree {$checkout->root}: the services are shared by the main checkout {$checkout->mainRoot} and all its worktrees. Stop them from the main checkout: cd {$checkout->mainRoot} && composer services:down",
            ),
        };
    }

    public function refused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * @return list<string>
     */
    private static function compose(Checkout $checkout): array
    {
        return ['docker', 'compose', '--file', $checkout->mainRoot.'/'.self::COMPOSE_FILE, '--project-directory', $checkout->mainRoot];
    }
}
