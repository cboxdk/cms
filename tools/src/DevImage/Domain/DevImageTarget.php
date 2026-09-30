<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

use Cbox\Cms\Tooling\Services\Domain\Checkout;
use Cbox\Cms\Tooling\Services\Domain\HostUser;
use InvalidArgumentException;

/**
 * What a run in the dev image needs from the host: the checkout and its main checkout, the host
 * user, the host's name, the host's `~/.pest` directory, the network of the shared services, the
 * directories outside the checkout the command writes to, whether the terminal is interactive, the
 * host variables passed on, and the command.
 */
final readonly class DevImageTarget
{
    /**
     * @param  list<string>  $extraMounts  absolute directories mounted at the same path
     * @param  array<string, string>  $passedEnvironment  host variables passed on as they are
     * @param  list<string>  $command  the program and its arguments, run in the checkout
     */
    public function __construct(
        public Checkout $checkout,
        public HostUser $user,
        public string $hostname,
        public string $pestDirectory,
        public string $network,
        public array $extraMounts,
        public bool $interactive,
        public array $passedEnvironment,
        public array $command,
    ) {
        if ($command === []) {
            throw new InvalidArgumentException('A run in the dev image needs a command.');
        }

        foreach ([$checkout->root, $checkout->mainRoot, $pestDirectory, ...$extraMounts] as $path) {
            if (! str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, ',')) {
                throw new InvalidArgumentException("A directory mounted into the dev image is an absolute path without a colon or a comma, which docker run -v cannot take, not [{$path}].");
            }
        }

        if ($hostname === '' || $network === '') {
            throw new InvalidArgumentException('A run in the dev image needs the host name and the network of the shared services.');
        }
    }
}
