<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use JsonException;
use UnexpectedValueException;

/**
 * A JSON document that a generated codec wrote, as the value of an Inertia prop or flash entry:
 * the codec fixes the form (GUARDRAILS 2.2), and this only turns its canonical text into the
 * arrays Inertia serialises again for the page.
 */
#[Internal]
final readonly class InertiaProps
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException when the text is not a JSON object
     */
    public static function document(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('A codec wrote text that is not JSON: '.$exception->getMessage(), 0, $exception);
        }

        return is_array($value) ? $value : throw new UnexpectedValueException('A codec wrote a JSON document that is not an object.');
    }
}
