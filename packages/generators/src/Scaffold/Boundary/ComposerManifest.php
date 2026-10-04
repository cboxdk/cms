<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonPackage;
use JsonException;

/**
 * An addon's composer.json as the scaffold reads it: the package's name, the first PSR-4 namespace
 * of its autoload, the first of its autoload-dev or the code's namespace with `Tests`, and the
 * first provider of extra.laravel.providers.
 */
#[Internal]
final readonly class ComposerManifest
{
    private function __construct() {}

    /**
     * @throws GenerationFailed with generate_invalid_config when the JSON has no name or no PSR-4 namespace
     */
    public static function read(string $json, string $path): AddonPackage
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('%s is not well-formed JSON: %s', $path, $exception->getMessage()), $exception);
        }

        $manifest = is_array($decoded) ? $decoded : [];
        $name = $manifest['name'] ?? null;

        if (! is_string($name) || preg_match('/\A[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z/', $name) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('%s names no package: give it a name of the form vendor/package.', $path));
        }

        $namespace = self::firstNamespace($manifest['autoload'] ?? null);

        if ($namespace === null) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('%s has no PSR-4 namespace in autoload, so the scaffold cannot name the addon\'s tests.', $path));
        }

        $extra = is_array($manifest['extra'] ?? null) ? $manifest['extra'] : [];
        $laravel = is_array($extra['laravel'] ?? null) ? $extra['laravel'] : [];
        $providers = is_array($laravel['providers'] ?? null) ? array_values(array_filter($laravel['providers'], is_string(...))) : [];

        return new AddonPackage(
            $name,
            $namespace,
            self::firstNamespace($manifest['autoload-dev'] ?? null) ?? $namespace.'\\Tests',
            $providers === [] ? null : ltrim($providers[0], '\\'),
        );
    }

    /**
     * The first PSR-4 namespace of an autoload section, without its trailing separator.
     */
    private static function firstNamespace(mixed $autoload): ?string
    {
        $psr4 = is_array($autoload) && is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

        foreach (array_keys($psr4) as $namespace) {
            if (is_string($namespace) && $namespace !== '') {
                return rtrim($namespace, '\\');
            }
        }

        return null;
    }
}
