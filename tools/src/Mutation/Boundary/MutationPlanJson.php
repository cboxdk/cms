<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationPlan;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShard;
use InvalidArgumentException;
use JsonException;

/**
 * The JSON form of a MutationPlan, which the plan job writes and the verdict job reads:
 * `{"format": 1, "base": ..., "failure": ..., "shards": [{"index", "count", "sources": [{"path",
 * "name", "size"}]}]}`, and the matrix GitHub Actions takes from it.
 */
final readonly class MutationPlanJson
{
    public const int FORMAT = 1;

    public static function encode(MutationPlan $plan): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'base' => $plan->base,
            'failure' => $plan->failure,
            'shards' => array_map(static fn (MutationShard $shard): array => [
                'index' => $shard->index,
                'count' => $shard->count,
                'sources' => array_map(static fn (ChangedSource $source): array => ['path' => $source->path, 'name' => $source->name, 'size' => $source->size], $shard->sources),
            ], $plan->shards),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /**
     * The shard numbers of the plan as a JSON list, for a matrix of GitHub Actions.
     */
    public static function matrix(MutationPlan $plan): string
    {
        return json_encode(range(1, $plan->count()), JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidArgumentException when the text is not a plan in the format
     */
    public static function decode(string $json): MutationPlan
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The mutation plan is not JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['shards'] ?? null) || ! array_is_list($data['shards'])) {
            throw new InvalidArgumentException('The mutation plan is not in format '.self::FORMAT.'.');
        }

        $base = $data['base'] ?? null;
        $failure = $data['failure'] ?? null;

        if (! is_string($base) && $base !== null || ! is_string($failure) && $failure !== null) {
            throw new InvalidArgumentException('The base and failure of the mutation plan are strings or null.');
        }

        $shards = [];

        foreach ($data['shards'] as $shard) {
            $index = is_array($shard) ? ($shard['index'] ?? null) : null;
            $count = is_array($shard) ? ($shard['count'] ?? null) : null;
            $sources = is_array($shard) ? ($shard['sources'] ?? null) : null;

            if (! is_int($index) || ! is_int($count) || ! is_array($sources) || ! array_is_list($sources)) {
                throw new InvalidArgumentException('A shard of the mutation plan has an index, a count and a list of sources.');
            }

            $shards[] = new MutationShard($index, $count, array_map(self::source(...), $sources));
        }

        return new MutationPlan($base, $failure, $shards);
    }

    private static function source(mixed $source): ChangedSource
    {
        $path = is_array($source) ? ($source['path'] ?? null) : null;
        $name = is_array($source) ? ($source['name'] ?? null) : null;
        $size = is_array($source) ? ($source['size'] ?? null) : null;

        if (! is_string($path) || ! is_string($name) || ! is_int($size)) {
            throw new InvalidArgumentException('A source of the mutation plan has a path, a name and a size.');
        }

        return new ChangedSource($path, $name, $size);
    }
}
