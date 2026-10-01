<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs;

/**
 * A planted class that writes JSON but takes and gives no object, such as a class that holds the
 * names of headers next to the document it writes; HandWrittenCodecScan does not count it as a JSON
 * helper.
 */
final readonly class JsonConstantsSlip
{
    public const string MEDIA_TYPE = 'application/problem+json';

    /**
     * @param  list<string>  $names
     */
    public static function names(array $names): string
    {
        return (string) json_encode($names);
    }
}
