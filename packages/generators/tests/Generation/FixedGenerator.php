<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Override;

/**
 * A generator that produces the given paths below its directory.
 */
final readonly class FixedGenerator implements Generator
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(private string $directory, private array $paths) {}

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $this->directory;
    }

    #[Override]
    public function generate(ResolvedSchema $schema, GenerationTarget $target): array
    {
        return array_map(static fn (string $path): GeneratedFile => new GeneratedFile($path, $path."\n"), $this->paths);
    }
}
