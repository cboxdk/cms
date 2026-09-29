<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;

/**
 * The runtime module of the TypeScript validators, resources/typescript/validation.ts of the
 * generators module, which cms:generate writes unchanged next to the modules it generates.
 */
#[Internal]
final readonly class TypeScriptRuntime
{
    /** The module, relative to the generators module's directory. */
    public const string PATH = 'resources/typescript/validation.ts';

    /**
     * @param  ?string  $path  the module, or null for the one of this generators module
     */
    public function __construct(private ?string $path = null) {}

    /**
     * The source of the module.
     *
     * @throws GenerationFailed with generate_invalid_output when the module cannot be read
     */
    public function source(): string
    {
        $path = $this->path ?? dirname(__DIR__, 3).'/'.self::PATH;

        return LocalFile::contents($path) ?? throw GenerationFailed::because(
            GenerateErrorCode::InvalidOutput,
            sprintf('The runtime module %s of the TypeScript validators does not exist or cannot be read. Install cboxdk/cms again.', $path),
        );
    }
}
