<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\SelectDisplay;

/**
 * The `ui` of a field in its blueprint (gap G2): the hints a form takes for the input and nothing
 * the kernel reads. `summary` is the template of a repeated group, which names the group's
 * nested fields in braces, such as `{title} ({year})`; `display` belongs to a `select` field.
 * The blueprint schema holds both to those field types, and BlueprintRules holds the summary's
 * handles to the group's fields.
 */
#[Internal]
final readonly class FieldUi
{
    public const bool DEFAULT_READ_ONLY = false;

    public const bool DEFAULT_HIDDEN = false;

    public function __construct(
        public ?LocalizedText $instructions,
        public ?LocalizedText $inputHint,
        public bool $readOnly,
        public bool $hidden,
        public ?SelectDisplay $display,
        public ?string $summary,
    ) {}

    /**
     * The handles the summary template names in braces, in template order, each once.
     *
     * @return list<string>
     */
    public function summaryHandles(): array
    {
        if ($this->summary === null || preg_match_all('/\{([^{}]*)\}/', $this->summary, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}
