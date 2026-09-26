<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * What mutation on changed files mutates: the sources below packages/<package>/src that changed
 * between the merge base of CMS_CI_BASE_REF and HEAD, or why that base could not be found. A
 * missing base is a failure, never an empty change, so the step cannot pass by mutating nothing.
 */
final readonly class MutationScope
{
    /**
     * @param  list<ChangedSource>  $sources
     */
    private function __construct(
        public ?string $base,
        public array $sources,
        public ?string $failure,
    ) {}

    /**
     * @param  string  $base  how the base was found, such as the merge base's commit and the ref
     * @param  list<ChangedSource>  $sources
     */
    public static function changed(string $base, array $sources): self
    {
        if ($base === '' || str_contains($base, "\n")) {
            throw new InvalidArgumentException('The base of the change needs a one-line description.');
        }

        $paths = array_map(static fn (ChangedSource $source): string => $source->path, $sources);

        if (count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException('A changed source is listed twice.');
        }

        sort($paths);
        $byPath = array_combine(array_map(static fn (ChangedSource $source): string => $source->path, $sources), $sources);

        return new self($base, array_map(static fn (string $path): ChangedSource => $byPath[$path], $paths), null);
    }

    public static function unresolved(string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException('An unresolved base needs a reason.');
        }

        return new self(null, [], $reason);
    }

    /**
     * The changed sources whose mutations the Postgres suite may kill, or the others.
     *
     * @return list<ChangedSource>
     */
    public function sources(bool $needPostgres): array
    {
        return array_values(array_filter($this->sources, static fn (ChangedSource $source): bool => $source->needsPostgres() === $needPostgres));
    }
}
