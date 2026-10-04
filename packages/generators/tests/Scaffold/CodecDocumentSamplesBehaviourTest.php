<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Generators\Scaffold\Adapter\CodecDocumentSamples;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * DocumentSamplesBehaviour against the samples from the codecs' JSON Schemas: the kernel's command
 * codecs, which have entry.create@1, and the tally queries' codecs, which have tally.notes@1.
 */
final class CodecDocumentSamplesBehaviourTest extends TestCase
{
    use DocumentSamplesBehaviour;

    #[Override]
    protected function documentSamples(): DocumentSamples
    {
        return new CodecDocumentSamples(app(CommandCodecs::class), ScaffoldWorld::queries());
    }
}
