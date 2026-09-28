<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupRepeat;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `group`: nested fields, once or repeated (PRD 11.6). The nested fields may
 * be of any registered field type and have no classification of their own.
 */
#[Internal]
final readonly class GroupFieldType implements FieldType
{
    public const string NAME = 'group';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['fields', 'repeat'];
    }

    #[Override]
    public function options(FieldValues $field): ?GroupOptions
    {
        $fields = $field->fields('fields');
        $repeat = null;

        if ($field->has('repeat')) {
            $values = $field->object('repeat');

            if (! $values instanceof FieldValues) {
                return null;
            }

            $values->knownKeys(['min_items', 'max_items']);
            $repeat = new GroupRepeat(
                $values->optionalInt('min_items'),
                $values->optionalInt('max_items') ?? GroupRepeat::DEFAULT_MAX_ITEMS,
            );
        }

        return $fields === null ? null : new GroupOptions($fields, $repeat);
    }
}
