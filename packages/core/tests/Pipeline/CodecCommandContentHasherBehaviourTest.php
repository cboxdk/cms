<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReviseEntryCodecV1;
use Cbox\Cms\Core\Pipeline\Boundary\CodecCommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandContentHasherBehaviour against the hasher the core binds, over the generated codec of
 * entry.revise, registered also as version 2 and under another name for the test.
 */
final class CodecCommandContentHasherBehaviourTest extends TestCase
{
    use CommandContentHasherBehaviour;

    #[Override]
    protected function hasher(): CommandContentHasher
    {
        $schema = new JsonSchema(ReviseEntryCodecV1::SCHEMA);

        return new CodecCommandContentHasher(new CommandCodecs(
            ReviseEntryCodecV1::commandCodec(),
            new CommandCodec(new CommandName('entry.revise'), 2, new ReviseEntryCodecV1, $schema),
            new CommandCodec(new CommandName(self::OTHER_NAME), 1, new ReviseEntryCodecV1, $schema),
        ));
    }
}
