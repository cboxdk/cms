<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryFragmentCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryMeta;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use LogicException;
use Override;

/**
 * The delivery API's documents through their generated codecs (PRD 8.8, 8.9, 8.12, GUARDRAILS 2.2),
 * each canonical JSON: keys sorted, no whitespace.
 *
 * - A record: delivery.v1.json by DeliveryCodecV1, with the record embedded exactly as the type's
 *   generated codec wrote it.
 * - A problem: problem.v1.json by ProblemCodecV1.
 * - An explanation: delivery-explanation.v1.json by DeliveryExplanationCodecV1, with the
 *   explanation written by PathExplanationCodecV1 and the problem by ProblemCodecV1.
 * - A fragment: delivery-fragment.v1.json by DeliveryFragmentCodecV1; stored() gives null for any
 *   bytes the codec does not read, so a damaged fragment is rebuilt instead of served.
 */
#[Internal]
final readonly class JsonDeliveryDocuments implements DeliveryDocuments
{
    public function __construct(
        private DeliveryCodecV1 $records = new DeliveryCodecV1,
        private DeliveryExplanationCodecV1 $explanations = new DeliveryExplanationCodecV1,
        private PathExplanationCodecV1 $paths = new PathExplanationCodecV1,
        private ProblemCodecV1 $problems = new ProblemCodecV1,
        private DeliveryFragmentCodecV1 $fragments = new DeliveryFragmentCodecV1,
    ) {}

    #[Override]
    public function body(DeliveryAnswer $answer): string
    {
        return match ($answer->format()) {
            AnswerFormat::Record => $this->records->encode(new DeliveryDocument($this->record($answer), $this->meta($answer)), ClassificationAccess::Public),
            AnswerFormat::Problem => $this->problem($answer->problem ?? throw new LogicException('A problem answer holds its problem.')),
            AnswerFormat::Explanation => $this->explanation($answer),
        };
    }

    #[Override]
    public function fragment(StoredAnswer $answer): string
    {
        return $this->fragments->encode($answer, ClassificationAccess::Public);
    }

    #[Override]
    public function stored(string $fragment): ?StoredAnswer
    {
        try {
            return $this->fragments->decode($fragment, ClassificationAccess::Public);
        } catch (DecodingFailed) {
            return null;
        }
    }

    private function explanation(DeliveryAnswer $answer): string
    {
        $explanation = $answer->explanation ?? throw new LogicException('An explanation answer holds its explanation.');
        $problem = $answer->problem;

        return $this->explanations->encode(new DeliveryExplanation(
            data: $problem instanceof Problem ? null : $this->record($answer),
            explanation: new JsonDocument($this->paths->encode($explanation, ClassificationAccess::Public)),
            meta: $problem instanceof Problem ? null : $this->meta($answer),
            problem: $problem instanceof Problem ? new JsonDocument($this->problem($problem)) : null,
            status: $answer->status,
        ), ClassificationAccess::Public);
    }

    private function problem(Problem $problem): string
    {
        return $this->problems->encode($problem, ClassificationAccess::Public);
    }

    private function record(DeliveryAnswer $answer): JsonDocument
    {
        return new JsonDocument($answer->record ?? throw new LogicException('A record answer holds its record.'));
    }

    private function meta(DeliveryAnswer $answer): DeliveryMeta
    {
        return new DeliveryMeta(
            canonicalUrl: $answer->canonicalUrl,
            contract: RecordCodecs::VERSION,
            locale: $answer->locale ?? throw new LogicException('A record answer holds its locale.'),
            type: $answer->type ?? throw new LogicException('A record answer holds its type.'),
        );
    }
}
