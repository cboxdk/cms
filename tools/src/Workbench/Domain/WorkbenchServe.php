<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Workbench\Domain;

use Cbox\Cms\Tooling\DevImage\Domain\PublishedPort;

/**
 * `composer workbench:serve`: the workbench, with its panel at /cms, served by Laravel's development
 * server (`vendor/bin/testbench serve`) in a container of the dev image for the checkout (DevImageRun),
 * on the network of the shared services, which it reaches by their names as the gates do. The
 * server listens on CONTAINER_PORT on every interface of the container, and the container's port is
 * published on the host's 127.0.0.1 only, on DEFAULT_PORT unless `--port=<n>` names another.
 *
 * Before it starts a container it checks what the panel needs on the host, so a missing piece is a
 * message with its fix and not a page that fails in the browser: an application key in workbench/.env,
 * the same file in Testbench's application, and the panel's build.
 */
final readonly class WorkbenchServe
{
    /** The host port the workbench is served on unless `--port=<n>` names another. */
    public const int DEFAULT_PORT = 8080;

    /** The port the development server listens on in the container. */
    public const int CONTAINER_PORT = 8080;

    /** Where the panel is mounted in the workbench. */
    public const string PANEL_PATH = '/cms';

    /** The Vite manifest of the panel's build, which `composer panel:build` writes, relative to the checkout. */
    public const string PANEL_MANIFEST = 'packages/panel/dist/.vite/manifest.json';

    public function __construct(public PublishedPort $port) {}

    public static function on(int $hostPort): self
    {
        return new self(new PublishedPort($hostPort, self::CONTAINER_PORT));
    }

    /**
     * The command the container runs: the development server on every interface of the container,
     * without questions, since nobody answers them.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return ['php', 'vendor/bin/testbench', 'serve', '--host=0.0.0.0', '--port='.$this->port->containerPort, '--no-interaction'];
    }

    /** The address of the panel on the host. */
    public function panelUrl(): string
    {
        return $this->port->url().self::PANEL_PATH;
    }

    /**
     * What is missing for the panel, each with its fix; empty when nothing is. $settings is
     * workbench/.env and $applicationSettings the copy Testbench's application reads, each null
     * when the file is missing; Testbench makes the copy only when there is none, so a copy that
     * differs is stale.
     *
     * @return list<string>
     */
    public static function problems(?string $settings, ?string $applicationSettings, bool $hasBuild): array
    {
        $problems = [];

        if ($settings === null || ! WorkbenchEnvironment::hasKey($settings)) {
            $problems[] = sprintf('%s has no APP_KEY, without which the panel refuses every request. Run composer dev:prepare, which writes one.', WorkbenchEnvironment::FILE);
        } elseif ($applicationSettings !== null && $applicationSettings !== $settings) {
            $problems[] = sprintf('%s is not what %s holds, and Testbench\'s application reads only its own copy. Run composer dev:prepare, which copies it.', WorkbenchEnvironment::APPLICATION_FILE, WorkbenchEnvironment::FILE);
        }

        if (! $hasBuild) {
            $problems[] = sprintf('The panel has no build (%s is missing), so its pages cannot load their scripts. Run composer panel:build, or composer dev:prepare, which runs it.', self::PANEL_MANIFEST);
        }

        return $problems;
    }
}
