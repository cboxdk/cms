<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Mcp\Tests\Fixtures\Surface\ReadAgentCards;
use Override;

/**
 * A query codec that refuses every document as the codecs refuse a document that is no object,
 * without a path.
 *
 * @implements JsonCodec<ReadAgentCards>
 */
final readonly class PathlessRefusalCodec implements JsonCodec
{
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        return '{}';
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ReadAgentCards
    {
        throw DecodingFailed::invalid(null, 'refused as a whole');
    }
}
