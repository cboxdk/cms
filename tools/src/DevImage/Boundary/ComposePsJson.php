<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Boundary;

use Cbox\Cms\Tooling\DevImage\Domain\ServiceState;
use JsonException;
use UnexpectedValueException;

/**
 * Reads the output of `docker compose ps --all --format json`: one JSON object per container and
 * line, as Docker Compose 2.21 and later print it, or one JSON array of them, as older versions
 * do. Each object gives Service, State, Health and Networks, a comma-separated list.
 */
final readonly class ComposePsJson
{
    /**
     * @return list<ServiceState>
     */
    public static function decode(string $output): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        $rows = str_starts_with($output, '[')
            ? self::json($output)
            : array_map(self::json(...), array_values(array_filter(explode("\n", $output), static fn (string $line): bool => trim($line) !== '')));

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UnexpectedValueException('docker compose ps printed JSON that is not a list of containers.');
        }

        return array_map(self::state(...), $rows);
    }

    private static function state(mixed $row): ServiceState
    {
        if (! is_array($row) || ! is_string($row['Service'] ?? null) || ! is_string($row['State'] ?? null)) {
            throw new UnexpectedValueException('docker compose ps printed a container without Service and State.');
        }

        $health = $row['Health'] ?? '';
        $networks = $row['Networks'] ?? '';

        return new ServiceState(
            $row['Service'],
            $row['State'],
            is_string($health) ? $health : '',
            is_string($networks) ? array_values(array_filter(array_map(trim(...), explode(',', $networks)), static fn (string $network): bool => $network !== '')) : [],
        );
    }

    private static function json(string $text): mixed
    {
        try {
            return json_decode(trim($text), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('docker compose ps printed what is not JSON: '.$exception->getMessage(), 0, $exception);
        }
    }
}
