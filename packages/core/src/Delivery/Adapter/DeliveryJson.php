<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use stdClass;

/**
 * The JSON text of the delivery API's own objects, its meta, its explanation and its fragments
 * (PRD 8.9, 8.12), through the core's JsonText, so they are canonical like the generated codecs'
 * output. A record and a problem are never written here: their generated codecs write them.
 */
#[Internal]
final readonly class DeliveryJson
{
    public static function encode(stdClass $object): string
    {
        return JsonText::encode($object);
    }

    /**
     * The object the text holds, or null when it is not a well-formed JSON object.
     */
    public static function decode(string $json): ?stdClass
    {
        try {
            return JsonText::decode($json);
        } catch (DecodingFailed) {
            return null;
        }
    }
}
