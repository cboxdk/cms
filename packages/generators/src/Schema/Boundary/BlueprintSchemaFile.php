<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Composer\InstalledVersions;
use JsonException;
use OutOfBoundsException;
use stdClass;

/**
 * The blueprint schema v1, blueprint.v1.json, as the installed cboxdk/cms-contracts ships it (PRD
 * 11.12, blueprint decision 5). The reader validates against this file in place, found through
 * Composer's InstalledVersions, and never keeps a copy, so the editor, which is pointed at the same
 * file, and cms:generate cannot disagree about a blueprint.
 *
 * A path given to the constructor replaces the lookup; tests use it to stand in for another release
 * of the contracts.
 */
#[Internal]
final readonly class BlueprintSchemaFile
{
    public const string PACKAGE = 'cboxdk/cms-contracts';

    public const string PATH_IN_PACKAGE = 'resources/schemas/blueprint.v1.json';

    public function __construct(private ?string $path = null) {}

    /**
     * The path of the schema file: the given one, or the one in the installed cboxdk/cms-contracts.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the package is not installed
     */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        try {
            $directory = InstalledVersions::getInstallPath(self::PACKAGE);
        } catch (OutOfBoundsException $missing) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'Composer does not know %s, which holds the blueprint schema. Run `composer install`.',
                self::PACKAGE,
            ), $missing);
        }

        if ($directory === null) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'Composer has no install path for %s, which holds the blueprint schema. Run `composer install`.',
                self::PACKAGE,
            ));
        }

        return rtrim($directory, '/\\').'/'.self::PATH_IN_PACKAGE;
    }

    /**
     * The decoded schema, with JSON objects as stdClass as the validator reads them.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the file is missing or not a JSON object
     */
    public function load(): stdClass
    {
        $path = $this->path();
        $contents = LocalFile::contents($path);

        if ($contents === null) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The blueprint schema %s does not exist or cannot be read. Reinstall %s with `composer install`.',
                $path,
                self::PACKAGE,
            ));
        }

        try {
            $schema = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('The blueprint schema %s is not valid JSON: %s', $path, $invalid->getMessage()), $invalid);
        }

        if (! $schema instanceof stdClass) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('The blueprint schema %s is not a JSON object.', $path));
        }

        return $schema;
    }
}
