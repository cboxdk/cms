<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every DocumentSamples does (PRD 13.4), run against CodecDocumentSamples over the kernel's
 * command codecs and the tally queries' codecs and against FakeDocumentSamples, so the fake the
 * scaffold actions' tests use cannot drift from the codecs (GUARDRAILS 9): a command with a
 * codec gives its document as an object literal with the schema's required properties, a data
 * query with a codec gives its result the same way, and one without a codec is refused with
 * generate_schema_missing naming it.
 */
trait DocumentSamplesBehaviour
{
    /**
     * Samples that know entry.create@1 and the data query tally.notes@1, and no other command or
     * query.
     */
    abstract protected function documentSamples(): DocumentSamples;

    #[Test]
    public function it_gives_the_document_of_a_command_with_a_codec_as_an_object_literal(): void
    {
        $literal = $this->documentSamples()->command(CommandRef::fromString('entry.create@1'));
        $printed = LiteralPrinter::print($literal, 0, 0, 1);

        Assert::assertStringStartsWith('{', $printed);
        Assert::assertStringContainsString('fields:', $printed);
    }

    #[Test]
    public function it_gives_the_result_of_a_data_query_with_a_codec_as_an_object_literal(): void
    {
        $literal = $this->documentSamples()->result(CommandRef::fromString('tally.notes@1'));
        $printed = LiteralPrinter::print($literal, 0, 0, 1);

        Assert::assertStringStartsWith('{', $printed);
        Assert::assertStringContainsString('count:', $printed);
    }

    #[Test]
    public function it_refuses_a_command_and_a_query_without_a_codec(): void
    {
        $samples = $this->documentSamples();

        foreach (['command' => 'tally.notes@1', 'result' => 'entry.create@1'] as $method => $ref) {
            try {
                $samples->{$method}(CommandRef::fromString($ref));
            } catch (GenerationFailed $refused) {
                Assert::assertSame(GenerateErrorCode::SchemaMissing, $refused->problems[0]->code);
                Assert::assertStringContainsString($ref.' has no codec', $refused->problems[0]->describe());

                continue;
            }

            Assert::fail(sprintf('%s() gave a sample for %s, which has no codec.', $method, $ref));
        }
    }
}
