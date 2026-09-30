<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\TimeWindow;
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

/**
 * The JSON form of a resolution's explanation (GUARDRAILS 5, "Forklaringer fra dag ét"): the one
 * encoding of the PathExplanation path.resolve returns, which every surface that shows why a page
 * looks as it does prints, `cms:explain --json` and the explanation of `GET /v1/resolve`, so no
 * surface has explain code of its own.
 *
 * Each step is an object, or null when the resolution did not reach it (mount is null too for a
 * node that is not a mount). Every key is always present, a value that does not apply is null, and
 * keys are sorted, so the same explanation gives the same document. Times are UTC with
 * microseconds, as `2026-09-30T12:00:00.000000Z`. The explanation holds ids, handles, the path and
 * the decisions, never a field of the entry.
 */
#[Internal]
final readonly class PathExplanationJson
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PathExplanation $explanation): array
    {
        return [
            'canonical' => $explanation->canonical instanceof CanonicalStep ? self::canonical($explanation->canonical) : null,
            'mount' => $explanation->mount instanceof MountStep ? self::mount($explanation->mount) : null,
            'node' => $explanation->node instanceof NodeStep ? self::node($explanation->node) : null,
            'outcome' => $explanation->outcome->value,
            'placement' => $explanation->placement instanceof PlacementStep ? self::placement($explanation->placement) : null,
            'route' => $explanation->route instanceof RouteStep ? self::route($explanation->route) : null,
            'site' => self::site($explanation->site),
            'visibility' => $explanation->visibility instanceof VisibilityStep ? self::visibility($explanation->visibility) : null,
        ];
    }

    /**
     * A time as the document writes it: UTC with microseconds.
     */
    public static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @return array{handle: ?string, host: string, locale: string, locale_published: bool, site: ?string}
     */
    private static function site(SiteStep $step): array
    {
        return [
            'handle' => $step->handle?->value,
            'host' => $step->host->value,
            'locale' => $step->locale->toString(),
            'locale_published' => $step->localePublished,
            'site' => $step->site?->toString(),
        ];
    }

    /**
     * @return array{candidates: list<string>, path: string, rest: ?string, route: ?string}
     */
    private static function route(RouteStep $step): array
    {
        return [
            'candidates' => $step->candidates,
            'path' => $step->path->value,
            'rest' => $step->rest,
            'route' => $step->route,
        ];
    }

    /**
     * @return array{kind: string, node: string}
     */
    private static function node(NodeStep $step): array
    {
        return ['kind' => $step->kind->value, 'node' => $step->node->toString()];
    }

    /**
     * @return array{mount: string, source: string}
     */
    private static function mount(MountStep $step): array
    {
        return ['mount' => $step->mount->toString(), 'source' => $step->source->toString()];
    }

    /**
     * @return array{canonical: bool, entry: ?string, looked_under: string, placement: ?string, routable: bool, slug: string, type: ?string}
     */
    private static function placement(PlacementStep $step): array
    {
        return [
            'canonical' => $step->canonical,
            'entry' => $step->entry?->toString(),
            'looked_under' => $step->lookedUnder->toString(),
            'placement' => $step->placement?->toString(),
            'routable' => $step->routable,
            'slug' => $step->slug->value,
            'type' => $step->type?->toString(),
        ];
    }

    /**
     * @return array{at: string, decision: string, lifecycle: ?string, release: ?string, rung: int, stored: string, valid_until: ?string, window: ?array{from: ?string, until: ?string}}
     */
    private static function visibility(VisibilityStep $step): array
    {
        return [
            'at' => self::time($step->at),
            'decision' => $step->decision->value,
            'lifecycle' => $step->lifecycle?->value,
            'release' => $step->release?->value,
            'rung' => $step->decision->rung(),
            'stored' => $step->stored->value,
            'valid_until' => $step->validUntil instanceof DateTimeImmutable ? self::time($step->validUntil) : null,
            'window' => $step->window instanceof TimeWindow ? [
                'from' => $step->window->from instanceof DateTimeImmutable ? self::time($step->window->from) : null,
                'until' => $step->window->until instanceof DateTimeImmutable ? self::time($step->window->until) : null,
            ] : null,
        ];
    }

    /**
     * @return array{here: bool, placement: ?string, url: ?string}
     */
    private static function canonical(CanonicalStep $step): array
    {
        return [
            'here' => $step->here,
            'placement' => $step->placement?->toString(),
            'url' => $step->url,
        ];
    }
}
