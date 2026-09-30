<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Routing\Boundary\PathExplanationJson;
use LogicException;
use Override;
use stdClass;

/**
 * The delivery API's documents as canonical JSON (PRD 8.8, 8.9, 8.12): keys sorted, no whitespace.
 *
 * - A record: `{"data":<record>,"meta":{"canonical_url":...,"contract":1,"locale":...,"type":...}}`,
 *   where the record is spliced in exactly as the type's generated codec wrote it.
 * - A problem: the problem details document, written by the generated ProblemCodecV1.
 * - An explanation: `{"data":<record>|null,"explanation":{...},"meta":{...}|null,
 *   "problem":<problem>|null,"status":<status>}`, where the explanation is written by
 *   PathExplanationJson, the one encoding of a PathExplanation, which cms:explain --json prints too.
 * - A fragment: `{"body":...,"format":...,"stale":...,"status":...}`; stored() gives null for any
 *   other bytes.
 */
#[Internal]
final readonly class JsonDeliveryDocuments implements DeliveryDocuments
{
    public function __construct(private ProblemCodecV1 $problems = new ProblemCodecV1) {}

    #[Override]
    public function body(DeliveryAnswer $answer): string
    {
        return match ($answer->format()) {
            AnswerFormat::Record => $this->record($answer),
            AnswerFormat::Problem => $this->problem($answer),
            AnswerFormat::Explanation => $this->explanation($answer),
        };
    }

    #[Override]
    public function fragment(StoredAnswer $answer): string
    {
        $fragment = new stdClass;
        $fragment->body = $answer->body;
        $fragment->format = $answer->format->value;
        $fragment->stale = $answer->stale;
        $fragment->status = $answer->status->value;

        return DeliveryJson::encode($fragment);
    }

    #[Override]
    public function stored(string $fragment): ?StoredAnswer
    {
        $read = DeliveryJson::decode($fragment);

        if (! $read instanceof stdClass) {
            return null;
        }

        $body = $read->body ?? null;
        $format = is_string($read->format ?? null) ? AnswerFormat::tryFrom($read->format) : null;
        $stale = $read->stale ?? null;
        $status = is_int($read->status ?? null) ? HttpStatus::tryFrom($read->status) : null;

        if (! is_string($body) || ! $format instanceof AnswerFormat || ! is_bool($stale) || ! $status instanceof HttpStatus || count(get_object_vars($read)) !== 4) {
            return null;
        }

        return new StoredAnswer($status, $format, $body, $stale);
    }

    private function record(DeliveryAnswer $answer): string
    {
        return '{"data":'.$this->recordJson($answer).',"meta":'.DeliveryJson::encode($this->meta($answer)).'}';
    }

    private function problem(DeliveryAnswer $answer): string
    {
        return $this->problems->encode($this->problemOf($answer), ClassificationAccess::Public);
    }

    private function explanation(DeliveryAnswer $answer): string
    {
        $explanation = $answer->explanation ?? throw new LogicException('An explanation answer holds its explanation.');
        $problem = $answer->problem instanceof Problem ? $this->problems->encode($answer->problem, ClassificationAccess::Public) : 'null';
        $record = $answer->problem instanceof Problem ? 'null' : $this->recordJson($answer);
        $meta = $answer->problem instanceof Problem ? 'null' : DeliveryJson::encode($this->meta($answer));

        return '{"data":'.$record
            .',"explanation":'.DeliveryJson::encode((object) PathExplanationJson::toArray($explanation))
            .',"meta":'.$meta
            .',"problem":'.$problem
            .',"status":'.$answer->status->value.'}';
    }

    private function recordJson(DeliveryAnswer $answer): string
    {
        return $answer->record ?? throw new LogicException('A record answer holds its record.');
    }

    private function problemOf(DeliveryAnswer $answer): Problem
    {
        return $answer->problem ?? throw new LogicException('A problem answer holds its problem.');
    }

    private function meta(DeliveryAnswer $answer): stdClass
    {
        $meta = new stdClass;
        $meta->canonical_url = $answer->canonicalUrl;
        $meta->contract = RecordCodecs::VERSION;
        $meta->locale = $answer->locale instanceof Locale ? $answer->locale->value : null;
        $meta->type = $answer->type instanceof TypeName ? $answer->type->value : null;

        return $meta;
    }
}
