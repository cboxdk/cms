<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs;

use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use stdClass;

/**
 * A planted JSON helper: a wrapper of JsonText that writes an object, which HandWrittenCodecScan
 * counts as JSON in a file that names a bound class, as the delivery API's hand-written DeliveryJson
 * was.
 */
final readonly class ObjectJsonSlip
{
    public static function encode(stdClass $object): string
    {
        return JsonText::encode($object);
    }
}
