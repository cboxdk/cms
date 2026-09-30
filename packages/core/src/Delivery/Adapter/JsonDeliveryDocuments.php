<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use DateTimeImmutable;
use DateTimeZone;
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
 *   "problem":<problem>|null,"status":<status>}`, with every step of the PathExplanation, ids in
 *   their canonical form and instants in UTC with microseconds.
 * - A fragment: `{"body":...,"format":...,"stale":...,"status":...}`; stored() gives null for any
 *   other bytes.
 */
#[Internal]
final readonly class JsonDeliveryDocuments implements DeliveryDocuments
{
    private const string INSTANT = 'Y-m-d\TH:i:s.u\Z';

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
            .',"explanation":'.DeliveryJson::encode($this->explained($explanation))
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

    private function explained(PathExplanation $explanation): stdClass
    {
        $json = new stdClass;
        $json->canonical = $explanation->canonical instanceof CanonicalStep ? $this->canonical($explanation->canonical) : null;
        $json->mount = $explanation->mount instanceof MountStep ? $this->mount($explanation->mount) : null;
        $json->node = $explanation->node instanceof NodeStep ? $this->node($explanation->node) : null;
        $json->outcome = $explanation->outcome->value;
        $json->placement = $explanation->placement instanceof PlacementStep ? $this->placement($explanation->placement) : null;
        $json->route = $explanation->route instanceof RouteStep ? $this->route($explanation->route) : null;
        $json->site = $this->site($explanation->site);
        $json->visibility = $explanation->visibility instanceof VisibilityStep ? $this->visibility($explanation->visibility) : null;

        return $json;
    }

    private function canonical(CanonicalStep $step): stdClass
    {
        $json = new stdClass;
        $json->here = $step->here;
        $json->placement = $step->placement?->toString();
        $json->url = $step->url;

        return $json;
    }

    private function mount(MountStep $step): stdClass
    {
        $json = new stdClass;
        $json->mount = $step->mount->toString();
        $json->source = $step->source->toString();

        return $json;
    }

    private function node(NodeStep $step): stdClass
    {
        $json = new stdClass;
        $json->kind = $step->kind->value;
        $json->node = $step->node->toString();

        return $json;
    }

    private function placement(PlacementStep $step): stdClass
    {
        $json = new stdClass;
        $json->canonical = $step->canonical;
        $json->entry = $step->entry?->toString();
        $json->looked_under = $step->lookedUnder->toString();
        $json->placement = $step->placement?->toString();
        $json->routable = $step->routable;
        $json->slug = $step->slug->value;
        $json->type = $step->type?->toString();

        return $json;
    }

    private function route(RouteStep $step): stdClass
    {
        $json = new stdClass;
        $json->candidates = $step->candidates;
        $json->path = $step->path->value;
        $json->rest = $step->rest;
        $json->route = $step->route;

        return $json;
    }

    private function site(SiteStep $step): stdClass
    {
        $json = new stdClass;
        $json->handle = $step->handle?->value;
        $json->host = $step->host->value;
        $json->locale = $step->locale->value;
        $json->locale_published = $step->localePublished;
        $json->site = $step->site?->toString();

        return $json;
    }

    private function visibility(VisibilityStep $step): stdClass
    {
        $json = new stdClass;
        $json->at = $this->instant($step->at);
        $json->decision = $step->decision->value;
        $json->lifecycle = $step->lifecycle?->value;
        $json->release = $step->release?->value;
        $json->rung = $step->decision->rung();
        $json->stored = $step->stored->value;
        $json->valid_until = $step->validUntil instanceof DateTimeImmutable ? $this->instant($step->validUntil) : null;
        $json->window = $step->window instanceof TimeWindow ? $this->window($step->window) : null;

        return $json;
    }

    private function window(TimeWindow $window): stdClass
    {
        $json = new stdClass;
        $json->from = $window->from instanceof DateTimeImmutable ? $this->instant($window->from) : null;
        $json->until = $window->until instanceof DateTimeImmutable ? $this->instant($window->until) : null;

        return $json;
    }

    private function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT);
    }
}
