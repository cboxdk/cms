<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\Yaml\Yaml;

/**
 * The packages the generators need that cboxdk/cms suggests and does not require (GUARDRAILS 2.6,
 * PRD 2.32): symfony/yaml and opis/json-schema read and validate the blueprint files. An
 * application installs them for development with require-dev, so they never reach production,
 * where the generators' service provider still loads. Reading blueprints without them fails with
 * a message that names each missing package, not with a missing class.
 */
#[Internal]
final readonly class SuggestedPackages
{
    /**
     * What reading blueprint files needs: each package with a class it provides.
     *
     * @var array<string, string>
     */
    public const array BLUEPRINT_READER = [
        'opis/json-schema' => CompliantValidator::class,
        'symfony/yaml' => Yaml::class,
    ];

    /**
     * @param  array<string, string>  $packages  each package with a class it provides
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when a package is not installed
     */
    public static function require(array $packages, string $purpose): void
    {
        $missing = array_keys(array_filter($packages, static fn (string $class): bool => ! class_exists($class)));

        if ($missing === []) {
            return;
        }

        sort($missing, SORT_STRING);

        throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
            '%s needs %s, which cboxdk/cms suggests and does not require. Install %s for development with `composer require --dev %s`.',
            $purpose,
            implode(' and ', $missing),
            count($missing) === 1 ? 'it' : 'them',
            implode(' ', $missing),
        ));
    }
}
