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
 * The blueprint schema v1, blueprint.v1.json, as the installed cboxdk/cms ships it in the contracts
 * module, packages/contracts/resources/schemas (PRD 2.32, 11.12, blueprint decision 5). The reader validates against this file in place, found through
 * Composer's InstalledVersions, and never keeps a copy, so the editor, which is pointed at the same
 * file, and cms:generate cannot disagree about a blueprint.
 *
 * A path given to the constructor replaces the lookup; tests use it to stand in for another release
 * of the contracts.
 */
#[Internal]
final readonly class BlueprintSchemaFile
{
    public const string PACKAGE = 'cboxdk/cms';

    public const string PATH_IN_PACKAGE = 'packages/contracts/resources/schemas/blueprint.v1.json';

    public function __construct(private ?string $path = null) {}

    /**
     * The path of the schema file: the given one, or the one in the installed cboxdk/cms.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the package is not installed
     */
    public function path(): string
    {
        return $this->path ?? $this->installPath().'/'.self::PATH_IN_PACKAGE;
    }

    /**
     * The path editors are pointed at (blueprint decision 3): the schema file in the installed
     * package's own directory, vendor/cboxdk/cms in an application, with the directories above that
     * one resolved to their real path. The package's directory itself is kept as Composer installed
     * it, so a vendor directory that is a symlink still gives a path through vendor. When cboxdk/cms
     * is the root package, as in its own repository, a checkout and a worktree, the package's
     * directory is the root, resolved to its real path. A path given to the constructor is kept as
     * its file name in the real path of its directory.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the package is not installed or the file is missing
     */
    public function editorPath(): string
    {
        if ($this->path !== null) {
            $directory = realpath(dirname($this->path));
            $path = is_string($directory) ? $directory.'/'.basename($this->path) : $this->path;
        } else {
            $path = self::editorDirectory($this->installPath(), $this->isRootPackage()).'/'.self::PATH_IN_PACKAGE;
        }

        if (! is_file($path)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The blueprint schema %s does not exist. Reinstall %s with `composer install`.',
                $path,
                self::PACKAGE,
            ));
        }

        return $path;
    }

    /**
     * The directory of the package as editors are pointed at it: for the root package the real path
     * of its install path, the root of the checkout; for an installed package the install path with
     * the directories above it resolved to their real path and its own directory kept as Composer
     * installed it, such as vendor/cboxdk/cms.
     *
     * @param  string  $installPath  the install path Composer reports, without a trailing separator
     */
    public static function editorDirectory(string $installPath, bool $rootPackage): string
    {
        if ($rootPackage) {
            $real = realpath($installPath);

            return is_string($real) ? $real : $installPath;
        }

        $parent = realpath(dirname($installPath));

        return (is_string($parent) ? $parent : dirname($installPath)).'/'.basename($installPath);
    }

    /**
     * Whether cboxdk/cms is the root package of an installation, whose install path is then the root
     * of the checkout. Every installation the registered autoloaders know is asked, not only the
     * first, because a tool with a bundled autoloader, such as Rector, registers its own root
     * package, which may come first.
     */
    private function isRootPackage(): bool
    {
        return array_any(
            InstalledVersions::getAllRawData(),
            static fn (array $installed): bool => $installed['root']['name'] === self::PACKAGE,
        );
    }

    /**
     * The directory Composer installed cboxdk/cms in, without a trailing separator.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the package is not installed
     */
    private function installPath(): string
    {
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

        return rtrim($directory, '/\\');
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
