<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery\Fakes;

use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Override;

/**
 * The delivery API's documents as plain lines (GUARDRAILS 9), held to JsonDeliveryDocuments by
 * DeliveryDocumentsBehaviour: an answer's body is one line per part it holds, the record verbatim,
 * so a test reads what an answer said; a fragment is its parts joined by a separator, and stored()
 * reads only what fragment() wrote.
 */
final class FakeDeliveryDocuments implements DeliveryDocuments
{
    private const string MARK = "fake-fragment\x1f";

    private const string END = "\x1ffake-end";

    /** How many bodies were written. */
    public int $written = 0;

    #[Override]
    public function body(DeliveryAnswer $answer): string
    {
        $this->written++;
        $lines = ['status '.$answer->status->value];

        if ($answer->record !== null) {
            $lines[] = 'record '.$answer->record;
            $lines[] = sprintf('meta %s %s %s', $answer->type?->value, $answer->locale?->value, $answer->canonicalUrl ?? '-');
        }

        if ($answer->problem instanceof Problem) {
            $lines[] = 'problem '.$answer->problem->code->value.' '.$answer->problem->detail;
        }

        if ($answer->explanation instanceof PathExplanation) {
            $lines[] = 'explanation '.$answer->explanation->outcome->value;
        }

        return implode("\n", $lines);
    }

    #[Override]
    public function fragment(StoredAnswer $answer): string
    {
        return self::MARK.implode("\x1f", [$answer->status->value, $answer->format->value, $answer->stale ? '1' : '0', $answer->body]).self::END;
    }

    #[Override]
    public function stored(string $fragment): ?StoredAnswer
    {
        if (! str_starts_with($fragment, self::MARK) || ! str_ends_with($fragment, self::END)) {
            return null;
        }

        $parts = explode("\x1f", substr($fragment, strlen(self::MARK), -strlen(self::END)), 4);

        if (count($parts) !== 4) {
            return null;
        }

        $status = HttpStatus::tryFrom((int) $parts[0]);
        $format = AnswerFormat::tryFrom($parts[1]);

        return $status instanceof HttpStatus && $format instanceof AnswerFormat && in_array($parts[2], ['0', '1'], true)
            ? new StoredAnswer($status, $format, $parts[3], $parts[2] === '1')
            : null;
    }
}
