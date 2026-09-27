<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * A PHP file below packages/<package>/src that changed since the base of the change, and the
 * class, enum, interface or trait it declares. Its layer, the innermost layer segment of the
 * namespace as in "Hvor ting bor", decides which tests can kill its mutations: a class in Adapter
 * or Infrastructure talks to Postgres, so the Postgres suite runs for it too.
 */
final readonly class ChangedSource
{
    /**
     * The layer segments of GUARDRAILS 2.5, as the Arch suite reads them.
     *
     * @var list<string>
     */
    public const array LAYERS = ['Domain', 'Actions', 'Boundary', 'Adapter', 'Infrastructure', 'Jobs', 'Http', 'Cli'];

    /**
     * The layers whose classes are mutated against the Postgres suite as well.
     *
     * @var list<string>
     */
    public const array POSTGRES_LAYERS = ['Adapter', 'Infrastructure'];

    /**
     * @param  string  $path  the file, relative to the root of the checkout
     * @param  string  $name  the fully qualified name of what the file declares, or the path when
     *                        it declares no class, enum, interface or trait
     */
    public function __construct(
        public string $path,
        public string $name,
    ) {
        if (preg_match('#^packages/[^/,]+/src/[^,]+\.php$#', $path) !== 1 || str_contains($path, '/../') || str_contains($path, '/./')) {
            throw new InvalidArgumentException("[{$path}] is not a PHP file below packages/<package>/src without a comma.");
        }

        if ($name === '' || str_contains($name, "\n")) {
            throw new InvalidArgumentException("The source [{$path}] needs a one-line name.");
        }
    }

    /**
     * The innermost layer segment of the namespace, or of the directories below src when the file
     * declares nothing, or null when it has none.
     */
    public function layer(): ?string
    {
        $segments = $this->name === $this->path
            ? explode('/', dirname(explode('/src/', $this->path, 2)[1]))
            : array_slice(explode('\\', $this->name), 0, -1);

        foreach (array_reverse($segments) as $segment) {
            if (in_array($segment, self::LAYERS, true)) {
                return $segment;
            }
        }

        return null;
    }

    public function needsPostgres(): bool
    {
        return in_array($this->layer(), self::POSTGRES_LAYERS, true);
    }
}
