<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Adapter\CodecContractSchemas;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\CreateNoteCodec;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ContractSchemasBehaviour against the adapter cms:build reads the schemas with: the command
 * codecs, no query codec, and the point schemas of Fixtures/PointSchemas, which also holds a file
 * whose name names no point and a directory that does not exist, which hold no schema.
 */
final class CodecContractSchemasBehaviourTest extends TestCase
{
    use ContractSchemasBehaviour;

    #[Override]
    protected function contractSchemas(): ContractSchemas
    {
        return new CodecContractSchemas(
            CreateNoteCodec::codecs(),
            new QueryCodecs,
            new PointSchemaDirectory(__DIR__.'/Fixtures/PointSchemas'),
            new PointSchemaDirectory(__DIR__.'/Fixtures/NoSuchDirectory'),
        );
    }
}
