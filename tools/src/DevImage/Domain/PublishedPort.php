<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

use InvalidArgumentException;

/**
 * A port of a dev image container published on the host (`docker run --publish`), such as the one
 * `composer workbench:serve` serves the workbench on. It binds to 127.0.0.1 only, as compose.yaml's
 * ports do: a port without a host address is published on every interface, which Docker Desktop
 * forwards to the LAN, and the workbench's credentials are fixed development values.
 */
final readonly class PublishedPort
{
    /** The host address every published port binds to. */
    public const string HOST_ADDRESS = '127.0.0.1';

    public function __construct(
        public int $hostPort,
        public int $containerPort,
    ) {
        foreach ([$hostPort, $containerPort] as $port) {
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("A published port is a TCP port from 1 to 65535, not [{$port}].");
            }
        }
    }

    /** The value of `docker run --publish`: `127.0.0.1:<host port>:<container port>`. */
    public function option(): string
    {
        return self::HOST_ADDRESS.':'.$this->hostPort.':'.$this->containerPort;
    }

    /** The address of the port on the host, as a browser there opens it. */
    public function url(): string
    {
        return 'http://'.self::HOST_ADDRESS.':'.$this->hostPort;
    }
}
