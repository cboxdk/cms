<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Editors\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use JsonException;
use stdClass;

/**
 * The shape of a field's value rule as the validation runtime reads it: its kind, and the kind of
 * its items when it is a list. The shared TypeEditors suite holds every leaf field's rule to it.
 */
#[Experimental]
final readonly class RuleShape
{
    public function __construct(
        public ?string $kind,
        public ?string $itemKind,
    ) {}

    /**
     * The shape of the rule a field carries; a kind that is missing or not a string is null.
     *
     * @throws JsonException when the document is not JSON, which JsonDocument never allows
     */
    public static function of(JsonDocument $rule): self
    {
        $decoded = json_decode($rule->value, false, 16, JSON_THROW_ON_ERROR);
        $kind = $decoded instanceof stdClass ? ($decoded->kind ?? null) : null;
        $item = $decoded instanceof stdClass ? ($decoded->item ?? null) : null;
        $itemKind = $item instanceof stdClass ? ($item->kind ?? null) : null;

        return new self(is_string($kind) ? $kind : null, is_string($itemKind) ? $itemKind : null);
    }
}
