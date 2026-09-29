<?php

declare(strict_types=1);

namespace Examples\Unit\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\AccessContext;

/**
 * How a surface serves a DTO: through the codec of the contract version it serves, with the
 * classification access of the call's AccessContext, so a field above it never leaves the server.
 */
final readonly class ServedRecord
{
    /**
     * @template TDto of object
     *
     * @param  JsonCodec<TDto>  $codec
     * @param  TDto  $dto
     */
    public static function body(JsonCodec $codec, object $dto, AccessContext $context): string
    {
        return $codec->encode($dto, $context->classificationAccess);
    }
}
