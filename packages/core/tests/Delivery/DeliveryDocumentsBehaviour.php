<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every DeliveryDocuments does, run against JsonDeliveryDocuments and FakeDeliveryDocuments,
 * so the fake the delivery's action tests use cannot drift from the documents the API sends
 * (GUARDRAILS 9): a record's body holds the record as the codec wrote it, a problem's its code, an
 * explanation's its outcome; a fragment reads back as the answer it was written from, and bytes
 * fragment() did not write read as no fragment.
 */
trait DeliveryDocumentsBehaviour
{
    public const string RECORD = '{"cms_id":"01936f5e-8a2b-7c3d-9e4f-0000000035e1","title":"The harbour opens"}';

    abstract protected function documents(): DeliveryDocuments;

    #[Test]
    public function it_writes_a_record_with_the_record_as_the_codec_wrote_it(): void
    {
        $body = $this->documents()->body(new DeliveryAnswer(HttpStatus::Ok, self::RECORD, new TypeName('app:article'), new Locale('da'), 'https://north.example/nyheder/harbour'));

        Assert::assertStringContainsString(self::RECORD, $body);
        Assert::assertStringContainsString('app:article', $body);
        Assert::assertStringContainsString('https://north.example/nyheder/harbour', $body);
    }

    #[Test]
    public function it_writes_a_problem_with_its_code_and_an_explanation_with_its_outcome(): void
    {
        $problem = Problem::of(ErrorCode::PathNotFound, 'Nothing is placed at /nyheder/gone.');
        $explanation = new PathExplanation(ResolveOutcome::NoPlacement, new SiteStep(new Host('north.example'), new Locale('da'), null, null, true));

        Assert::assertStringContainsString('path_not_found', $this->documents()->body(DeliveryAnswer::problem($problem)));
        Assert::assertStringContainsString('no_placement', $this->documents()->body(DeliveryAnswer::problem($problem, $explanation)));
    }

    #[Test]
    public function a_fragment_reads_back_as_the_answer_it_was_written_from(): void
    {
        $documents = $this->documents();

        foreach ([
            new StoredAnswer(HttpStatus::Ok, AnswerFormat::Record, '{"data":'.self::RECORD.'}', true),
            new StoredAnswer(HttpStatus::NotFound, AnswerFormat::Problem, "a body\nwith lines and \x1f", false),
            new StoredAnswer(HttpStatus::Gone, AnswerFormat::Problem, '', false),
        ] as $answer) {
            Assert::assertEquals($answer, $documents->stored($documents->fragment($answer)));
        }
    }

    #[Test]
    public function bytes_it_did_not_write_read_as_no_fragment(): void
    {
        $documents = $this->documents();
        $written = $documents->fragment(new StoredAnswer(HttpStatus::Ok, AnswerFormat::Record, self::RECORD, false));

        Assert::assertNull($documents->stored(''));
        Assert::assertNull($documents->stored(self::RECORD));
        Assert::assertNull($documents->stored(substr($written, 0, -3)));
    }
}
