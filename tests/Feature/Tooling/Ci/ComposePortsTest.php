<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Tooling\CiFiles;
use Symfony\Component\Yaml\Tag\TaggedValue;

/*
 * The services run with fixed development credentials: the Postgres superuser postgres/postgres,
 * cms_owner and cms_app, and Valkey without a password. A port published without a host address
 * binds to every interface, and Docker Desktop forwards that to the LAN, so anyone on the same
 * network could log in as the superuser and run shell commands with COPY ... FROM PROGRAM. Every
 * published port therefore binds to 127.0.0.1; the tests on the host reach the services there
 * (phpunit.xml), and the php container reaches them on the compose network.
 */

const LOOPBACK = '127.0.0.1';

/**
 * The host address a compose port entry binds to: '' for every interface, which is what an entry
 * without an address means, including one without a host port.
 */
function publishedPortHost(mixed $entry): string
{
    if (is_array($entry)) {
        $host = $entry['host_ip'] ?? '';

        return is_string($host) ? $host : '';
    }

    if (! is_string($entry) && ! is_int($entry)) {
        return '';
    }

    $spec = explode('/', (string) $entry, 2)[0];

    if (str_starts_with($spec, '[')) {
        $end = strpos($spec, ']');

        return $end === false ? '' : substr($spec, 1, $end - 1);
    }

    $parts = explode(':', $spec);

    return count($parts) >= 3 ? $parts[0] : '';
}

/**
 * Every port entry of every service in a compose file, keyed by service and position.
 *
 * @return array<string, mixed>
 */
function publishedPorts(string $file): array
{
    $services = CiFiles::at(CiFiles::yaml($file), 'services');
    $entries = [];

    foreach (is_array($services) ? $services : [] as $name => $service) {
        $ports = is_array($service) ? ($service['ports'] ?? []) : [];
        $ports = $ports instanceof TaggedValue ? $ports->getValue() : $ports;

        foreach (is_array($ports) ? $ports : [] as $index => $port) {
            $entries[$name.'.ports.'.$index] = $port;
        }
    }

    return $entries;
}

it('reads the host address of a compose port entry, and none means every interface', function (mixed $entry, string $host): void {
    expect(publishedPortHost($entry))->toBe($host);
})->with([
    'host and container port' => ['54317:5432', ''],
    'container port only' => ['5432', ''],
    'integer container port' => [5432, ''],
    'loopback' => ['127.0.0.1:54317:5432', LOOPBACK],
    'loopback with protocol' => ['127.0.0.1:63797:6379/tcp', LOOPBACK],
    'every interface spelled out' => ['0.0.0.0:54317:5432', '0.0.0.0'],
    'IPv6 loopback' => ['[::1]:54317:5432', '::1'],
    'long syntax with host_ip' => [['target' => 5432, 'published' => '54317', 'host_ip' => LOOPBACK], LOOPBACK],
    'long syntax without host_ip' => [['target' => 5432, 'published' => '54317'], ''],
]);

it('publishes the development services only on the loopback interface', function (string $file): void {
    $hosts = array_map(publishedPortHost(...), publishedPorts($file));

    expect(array_filter($hosts, static fn (string $host): bool => $host !== LOOPBACK))->toBe([]);
})->with([CiFiles::COMPOSE, CiFiles::COMPOSE_CI]);
