<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\FieldFallback;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A field of a type, an extension or a group, with the choices that every field has and the
 * options of its field type. A field's identity is its owner and its handle (PRD 11.2, 11.12).
 *
 * A top-level field has a classification (PRD 12.2); a field inside a group has none and inherits
 * the group's. The values the blueprint file leaves out have their defaults from the blueprint
 * schema v1: not required, not filterable, not sortable. Whether agents see a field the file does
 * not decide for follows its classification: a top-level field when it is public or internal
 * (Classification::seenByAgentsByDefault()), and a field inside a group when agents see the group.
 * `translatable` and `fallback` are the keys of a top-level field of a type with localization
 * variants (PRD 10.1, 10.3); a field inside a group has its group's, and both have their defaults
 * everywhere else.
 *
 * The presentation keys (gap G2) reach only the editor descriptor: `labels` and `descriptions`
 * are the label and the description per locale when the file gives a map, `ui` the hints of the
 * form and `default` the form's starting value; each is empty or null when the file has none.
 *
 * `when` is the condition the field is shown on and `whenHidden` what happens to its value while
 * it is hidden (gap G3); a conditional field has both, every other field neither.
 */
#[Internal]
final readonly class FieldBlueprint
{
    public const bool DEFAULT_REQUIRED = false;

    public const bool DEFAULT_FILTERABLE = false;

    public const bool DEFAULT_SORTABLE = false;

    public const bool DEFAULT_TRANSLATABLE = false;

    public const FieldFallback DEFAULT_FALLBACK = FieldFallback::Variant;

    /**
     * @param  ?string  $description  what the field holds; always present when agents see the field (PRD 14.5)
     * @param  bool  $agents  whether MCP tools and agents see the field
     * @param  bool  $translatable  whether a language's variant holds the field, in a type with localization variants
     * @param  FieldFallback  $fallback  how a translatable field falls back
     * @param  array<string, string>  $labels  the label by locale, in file order, when the file gives a map
     * @param  array<string, string>  $descriptions  the description by locale, in file order, when the file gives a map
     */
    public function __construct(
        public Handle $handle,
        public string $label,
        public ?string $description,
        public bool $required,
        public ?Classification $classification,
        public bool $filterable,
        public bool $sortable,
        public bool $agents,
        public FieldOptions $options,
        public Owner $owner,
        public SourceLocation $location,
        public bool $translatable = self::DEFAULT_TRANSLATABLE,
        public FieldFallback $fallback = self::DEFAULT_FALLBACK,
        public array $labels = [],
        public array $descriptions = [],
        public ?FieldUi $ui = null,
        public ?FieldDefault $default = null,
        public ?FieldCondition $when = null,
        public ?WhenHidden $whenHidden = null,
    ) {}
}
