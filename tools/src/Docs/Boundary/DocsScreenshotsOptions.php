<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\Screenshot;
use Cbox\Cms\Tooling\Docs\Domain\Screenshots;
use InvalidArgumentException;

/**
 * The arguments of `composer docs:screenshots -- [--only=<key>]...`: the shots to capture, by
 * default every shot of Screenshots, in its order.
 */
final readonly class DocsScreenshotsOptions
{
    public const string USAGE = 'Usage: php tools/bin/docs-screenshots.php [--only=<key>]...';

    /**
     * @param  list<Screenshot>  $shots
     */
    private function __construct(public array $shots) {}

    /**
     * @param  list<string>  $arguments
     * @param  list<Screenshot>  $manifest
     */
    public static function parse(array $arguments, array $manifest): self
    {
        $only = [];

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--only=')) {
                throw new InvalidArgumentException("Unknown argument [{$argument}].");
            }

            $key = substr($argument, strlen('--only='));

            if (! array_any($manifest, static fn (Screenshot $shot): bool => $shot->key === $key)) {
                throw new InvalidArgumentException("No screenshot has the key [{$key}]; the keys are ".implode(', ', array_map(static fn (Screenshot $shot): string => $shot->key, $manifest)).'.');
            }

            $only[$key] = true;
        }

        return new self($only === [] ? $manifest : array_values(array_filter($manifest, static fn (Screenshot $shot): bool => isset($only[$shot->key]))));
    }

    /**
     * The options for this repository's manifest.
     *
     * @param  list<string>  $arguments
     */
    public static function forRepository(array $arguments): self
    {
        return self::parse($arguments, Screenshots::all());
    }
}
