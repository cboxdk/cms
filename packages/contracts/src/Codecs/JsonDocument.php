<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use JsonException;
use stdClass;

/**
 * The JSON text of one object of another contract that a document holds (GUARDRAILS 2.2), such as
 * the record of an entry, which its type's generated codec writes, or a path explanation, which the
 * generated codec of path-explanation.v1.json writes. The codec of the document that holds it
 * embeds the object as it is and reads it back as the canonical JSON text of the object; it checks
 * only that it is an object, and the object's own codec and validator check its keys.
 */
#[Experimental]
final readonly class JsonDocument
{
    /** The depth the text is read to, as the kernel's codecs read a document. */
    private const int MAX_DEPTH = 64;

    /**
     * @throws InvalidJsonDocument when the text is not one well-formed JSON object
     */
    public function __construct(public string $value)
    {
        try {
            $decoded = json_decode($value, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidJsonDocument::malformed($exception);
        }

        if (! $decoded instanceof stdClass) {
            throw InvalidJsonDocument::notAnObject();
        }
    }
}
