<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Routing\Boundary\PathExplanationJson;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use DateTimeImmutable;

/**
 * What cms:explain prints (GUARDRAILS 5 and 7.1): why the page at a URL looks as it does, read
 * from the typed explanation path.resolve returns. It has no explain code of its own: the steps
 * are those of the PathExplanation, and --json writes them with PathExplanationJson, the encoding
 * every surface uses.
 *
 * - An answered read exits 0, whatever its outcome: the explanation is the answer, a page that does
 *   not resolve included. With --json one document, keys sorted: `{"content_keys": [...],
 *   "explanation": <PathExplanationJson>, "read_position": "<xmin>", "version": 1}`, where
 *   content_keys are the keys the answer is tagged with (PRD 9.4). Without it the outcome and a
 *   line per step the resolution reached, then the content keys and the read's position.
 * - A rejected read exits with the catalog's exit code of its first error and prints it as
 *   RefusalOutput does, the problem details with --json.
 */
#[Internal]
final readonly class ExplainOutput
{
    public const int VERSION = 1;

    public function __construct(private RefusalOutput $refusals) {}

    public function of(QueryResult $read, bool $json): CliAnswer
    {
        if (! $read->result instanceof ResolvedPath || ! $read->position instanceof CommitPosition) {
            if ($read->errors === []) {
                return new CliAnswer(ExitCode::Software, [], ['path.resolve answered with something other than a resolved path.']);
            }

            return $this->refusals->errors($json, $read->errors[0], ...array_slice($read->errors, 1));
        }

        $keys = array_map(static fn (DependencyKey $key): string => $key->toString(), $read->contentKeys);
        $explanation = $read->result->explanation;

        if ($json) {
            return new CliAnswer(ExitCode::Ok, [json_encode(
                [
                    'content_keys' => $keys,
                    'explanation' => PathExplanationJson::toArray($explanation),
                    'read_position' => $read->position->value,
                    'version' => self::VERSION,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )]);
        }

        return new CliAnswer(ExitCode::Ok, [
            ...$this->steps($explanation),
            sprintf('  %-12s %s', 'content keys', $keys === [] ? 'none' : implode(' ', $keys)),
            sprintf('  %-12s %s, the read saw every changeset below it', 'read at', $read->position->value),
        ]);
    }

    public function refused(CliCallRefused $refused, bool $json): CliAnswer
    {
        return $this->refusals->refused($refused, $json);
    }

    /**
     * @return list<string>
     */
    private function steps(PathExplanation $explanation): array
    {
        $site = $explanation->site;
        $lines = [
            sprintf('<info>%s%s</info> in %s: %s', $site->host->value, $explanation->route?->path->value ?? '', $site->locale->toString(), $explanation->outcome->value),
            sprintf(
                '  %-12s %s',
                'site',
                match (true) {
                    ! $site->handle instanceof SiteHandle => 'no configured site serves the host',
                    ! $site->site instanceof SiteId => sprintf('the host is configured for %s, but no site has that handle', $site->handle->value),
                    default => sprintf('%s (%s), %s %s', $site->handle->value, $site->site->toString(), $site->localePublished ? 'publishes in' : 'does not publish in', $site->locale->toString()),
                },
            ),
        ];

        if ($explanation->route instanceof RouteStep) {
            $lines[] = $this->route($explanation->route);
        }

        if ($explanation->node instanceof NodeStep) {
            $lines[] = sprintf('  %-12s %s, a %s', 'node', $explanation->node->node->toString(), $explanation->node->kind->value);
        }

        if ($explanation->mount instanceof MountStep) {
            $lines[] = sprintf('  %-12s shows the placements below %s as they are there', 'mount', $explanation->mount->source->toString());
        }

        if ($explanation->placement instanceof PlacementStep) {
            $lines[] = $this->placement($explanation->placement);
        }

        if ($explanation->visibility instanceof VisibilityStep) {
            $lines[] = $this->visibility($explanation->visibility);
        }

        if ($explanation->canonical instanceof CanonicalStep) {
            $lines[] = $this->canonical($explanation->canonical);
        }

        return $lines;
    }

    private function route(RouteStep $route): string
    {
        if ($route->route === null) {
            return sprintf('  %-12s none of %s is a route of the site in the locale', 'route', implode(', ', $route->candidates));
        }

        return sprintf('  %-12s %s, the longest of %s; the rest is "%s"', 'route', $route->route, implode(', ', $route->candidates), $route->rest ?? '');
    }

    private function placement(PlacementStep $placement): string
    {
        $looked = sprintf('slug %s below %s', $placement->slug->value, $placement->lookedUnder->toString());

        if (! $placement->placement instanceof PlacementId || ! $placement->entry instanceof EntryId) {
            return sprintf('  %-12s %s: no placement the reader can read', 'placement', $looked);
        }

        return sprintf(
            '  %-12s %s: placement %s of entry %s, %s, %s',
            'placement',
            $looked,
            $placement->placement->toString(),
            $placement->entry->toString(),
            $placement->type instanceof TypeId ? sprintf('type %s %s', $placement->type->toString(), $placement->routable ? 'with URLs' : 'without URLs') : 'whose entry the reader cannot read',
            $placement->canonical ? 'canonical' : 'not canonical',
        );
    }

    private function visibility(VisibilityStep $visibility): string
    {
        return sprintf(
            '  %-12s %s (rung %d) at %s; stored %s, window %s%s',
            'visibility',
            $visibility->decision->value,
            $visibility->decision->rung(),
            PathExplanationJson::time($visibility->at),
            $visibility->stored->value,
            $visibility->window instanceof TimeWindow ? $this->window($visibility->window) : 'none',
            $visibility->validUntil instanceof DateTimeImmutable ? '; valid until '.PathExplanationJson::time($visibility->validUntil) : '',
        );
    }

    private function window(TimeWindow $window): string
    {
        return sprintf(
            '%s to %s',
            $window->from instanceof DateTimeImmutable ? PathExplanationJson::time($window->from) : 'always',
            $window->until instanceof DateTimeImmutable ? PathExplanationJson::time($window->until) : 'open',
        );
    }

    private function canonical(CanonicalStep $canonical): string
    {
        if (! $canonical->placement instanceof PlacementId) {
            return sprintf('  %-12s no canonical placement the reader can read', 'canonical');
        }

        return sprintf(
            '  %-12s %s (placement %s)%s',
            'canonical',
            $canonical->url ?? 'no URL: its node has no route or its site is not configured',
            $canonical->placement->toString(),
            $canonical->here ? ', this URL' : ', not this URL',
        );
    }
}
