<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The field type `acme:colour` of the contracts' addon fixture, as another contributor than the
 * core would register it: its choices under `options`, a palette and whether a custom colour is
 * allowed.
 */
final readonly class ColourFieldType implements FieldType
{
    public const string NAME = 'acme:colour';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['options'];
    }

    #[Override]
    public function options(FieldValues $field): ?ColourOptions
    {
        if (! $field->has('options')) {
            return new ColourOptions(null, ColourOptions::DEFAULT_ALLOW_CUSTOM);
        }

        $options = $field->object('options');

        if (! $options instanceof FieldValues) {
            return null;
        }

        $options->knownKeys(['palette', 'allow_custom']);

        return new ColourOptions($options->optionalString('palette'), $options->bool('allow_custom', ColourOptions::DEFAULT_ALLOW_CUSTOM));
    }
}
