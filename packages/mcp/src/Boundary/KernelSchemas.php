<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Storage\LocalPath;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

/**
 * Reads the kernel's JSON Schemas that the MCP tools embed in their input schemas (GUARDRAILS 2.2):
 * envelope.v1.json, the envelope of a write, from the contracts module's resources/schemas in the
 * same package, cboxdk/cms. It reads a local file and never a URL: the path is fixed below this
 * module's directory, and one that names a stream wrapper is refused before any file function sees
 * it. The Arch suite allows SplFileObject here because of that (Egress).
 */
#[Internal]
final readonly class KernelSchemas
{
    /** The kernel's schemas, below the directory that holds the modules. */
    public const string DIRECTORY = 'contracts/resources/schemas';

    public const string ENVELOPE = 'envelope.v1.json';

    /**
     * @throws UnexpectedValueException when the schema cannot be read or is not a JSON Schema object
     */
    public static function envelope(): JsonSchema
    {
        return new JsonSchema(self::read(dirname(__DIR__, 3).'/'.self::DIRECTORY.'/'.self::ENVELOPE));
    }

    private static function read(string $path): string
    {
        if (LocalPath::namesStreamWrapper($path) || ! is_file($path) || ! is_readable($path)) {
            throw self::unreadable($path);
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 || $size === false ? '' : $file->fread($size);
        } catch (RuntimeException $failed) {
            throw self::unreadable($path, $failed);
        }

        return is_string($contents) && $contents !== '' ? $contents : throw self::unreadable($path);
    }

    private static function unreadable(string $path, ?RuntimeException $previous = null): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('The kernel schema %s of cboxdk/cms does not exist or cannot be read. Install cboxdk/cms again.', $path), 0, $previous);
    }
}
