<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Http\Inertia\Domain\Dto\InertiaMembers;
use stdClass;

/**
 * The body of an Inertia command request (GUARDRAILS 2.1): one JSON object with exactly two
 * members, `envelope`, the envelope fields of envelope.v1.json, and `command`, the command's
 * document, both objects. It splits the body into the two documents, each written again as
 * canonical JSON, so the generated codec of each reads its own document; the paths of their errors
 * are then relative to the member.
 */
#[Internal]
final readonly class InertiaDocument
{
    public const string ENVELOPE = 'envelope';

    public const string COMMAND = 'command';

    /**
     * @throws DecodingFailed with json_malformed for a body that is not a JSON object, and with
     *                        json_invalid for a member that is missing, unknown or not an object
     */
    public static function members(string $body): InertiaMembers
    {
        $document = JsonValues::object(JsonText::decode($body), null, [self::COMMAND, self::ENVELOPE]);

        return new InertiaMembers(
            JsonValues::required($document, self::ENVELOPE, null, self::member(...)),
            JsonValues::required($document, self::COMMAND, null, self::member(...)),
        );
    }

    /**
     * @throws DecodingFailed with json_invalid when the member is not an object
     */
    private static function member(mixed $value, FieldPath $at): string
    {
        if (! $value instanceof stdClass) {
            throw DecodingFailed::invalid($at, 'is not an object');
        }

        return JsonText::encode($value);
    }
}
