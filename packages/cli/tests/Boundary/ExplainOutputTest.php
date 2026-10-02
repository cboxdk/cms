<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\ExplainOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCount;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld as World;
use DateTimeImmutable;

/*
 * What cms:explain prints for each step and outcome of a resolution, from hand-built explanations:
 * the exact line of every step, with and without the parts a step may lack, the --json document,
 * a rejected read as RefusalOutput prints it, and an answer that is not a resolved path.
 */

const EXPLAIN_POSITION = '4711';

function explainPrinter(): ExplainOutput
{
    return new ExplainOutput(new RefusalOutput(new ProblemCodecV1));
}

function explainSite(?string $handle = 'north', ?string $site = World::NORTH_SITE, bool $published = true): SiteStep
{
    return new SiteStep(
        new Host('north.example'),
        new Locale('da'),
        $handle === null ? null : new SiteHandle($handle),
        $site === null ? null : SiteId::fromString($site),
        $published,
    );
}

/**
 * @param  list<DependencyKey>  $keys
 */
function explainRead(PathExplanation $explanation, array $keys = []): QueryResult
{
    return QueryResult::answered(new ResolvedPath(null, $explanation), $keys, new CommitPosition(EXPLAIN_POSITION));
}

/**
 * @return list<string>
 */
function explainLines(PathExplanation $explanation): array
{
    $answer = explainPrinter()->of(explainRead($explanation), false);

    expect($answer->exit)->toBe(ExitCode::Ok)->and($answer->errors)->toBe([]);

    return $answer->output;
}

function explainVisibility(?TimeWindow $window, ?DateTimeImmutable $validUntil): VisibilityStep
{
    return new VisibilityStep(
        VisibilityDecision::BeforeWindow,
        new DateTimeImmutable('2026-03-10T14:00:00.5+02:00'),
        null,
        null,
        Visibility::Scheduled,
        $window,
        $validUntil,
    );
}

it('prints only the outcome and the site of a host no configured site serves', function (): void {
    expect(explainLines(new PathExplanation(ResolveOutcome::UnknownHost, explainSite(null, null))))->toBe([
        '<info>north.example</info> in da: unknown_host',
        '  site         no configured site serves the host',
        '  content keys none',
        '  read at      4711, the read saw every changeset below it',
    ]);
});

it('names the handle the host is configured for when no site has it', function (): void {
    expect(explainLines(new PathExplanation(ResolveOutcome::UnknownSite, explainSite('north', null)))[1])
        ->toBe('  site         the host is configured for north, but no site has that handle');
});

it('says whether the site publishes in the locale', function (bool $published, string $line): void {
    expect(explainLines(new PathExplanation(ResolveOutcome::LocaleNotPublished, explainSite(published: $published)))[1])->toBe($line);
})->with([
    'published' => [true, '  site         north ('.World::NORTH_SITE.'), publishes in da'],
    'not published' => [false, '  site         north ('.World::NORTH_SITE.'), does not publish in da'],
]);

it('lists the prefixes looked up when none is a route', function (): void {
    expect(explainLines(new PathExplanation(
        ResolveOutcome::NoRoute,
        explainSite(),
        new RouteStep(new RequestPath('/a/b'), null, null),
    )))->toBe([
        '<info>north.example/a/b</info> in da: no_route',
        '  site         north ('.World::NORTH_SITE.'), publishes in da',
        '  route        none of /a/b, /a, / is a route of the site in the locale',
        '  content keys none',
        '  read at      4711, the read saw every changeset below it',
    ]);
});

it('prints the route found and the rest, empty when there is none', function (?string $rest, string $line): void {
    expect(explainLines(new PathExplanation(
        ResolveOutcome::NoSlug,
        explainSite(),
        new RouteStep(new RequestPath('/nyheder/harbour'), '/nyheder', $rest),
    ))[2])->toBe($line);
})->with([
    'a rest' => ['harbour', '  route        /nyheder, the longest of /nyheder/harbour, /nyheder, /; the rest is "harbour"'],
    'no rest' => [null, '  route        /nyheder, the longest of /nyheder/harbour, /nyheder, /; the rest is ""'],
]);

it('prints the node, the mount, the placement, the visibility and the canonical placement it reached', function (): void {
    $explanation = new PathExplanation(
        ResolveOutcome::NotVisible,
        explainSite(),
        new RouteStep(new RequestPath('/nyheder/harbour'), '/nyheder', 'harbour'),
        new NodeStep(NodeId::fromString(World::MOUNT), NodeKind::Mount),
        new MountStep(NodeId::fromString(World::MOUNT), NodeId::fromString(World::SECTION)),
        new PlacementStep(
            NodeId::fromString(World::SECTION),
            new Slug('harbour'),
            PlacementId::fromString(World::PLACEMENT),
            EntryId::fromString(World::ENTRY),
            TypeId::fromString(World::ARTICLE),
            canonical: true,
            routable: true,
        ),
        explainVisibility(
            new TimeWindow(new DateTimeImmutable('2026-03-10T15:00:00+02:00'), new DateTimeImmutable('2026-03-10T20:00:00Z')),
            new DateTimeImmutable('2026-03-10T15:00:00+02:00'),
        ),
        new CanonicalStep(PlacementId::fromString(World::PLACEMENT), 'https://north.example/nyheder/harbour', true),
    );
    $keys = [DependencyKey::node(NodeId::fromString(World::SECTION)), DependencyKey::entry(EntryId::fromString(World::ENTRY))];

    $answer = explainPrinter()->of(explainRead($explanation, $keys), false);

    expect($answer->exit)->toBe(ExitCode::Ok)->and($answer->output)->toBe([
        '<info>north.example/nyheder/harbour</info> in da: not_visible',
        '  site         north ('.World::NORTH_SITE.'), publishes in da',
        '  route        /nyheder, the longest of /nyheder/harbour, /nyheder, /; the rest is "harbour"',
        '  node         '.World::MOUNT.', a mount',
        '  mount        shows the placements below '.World::SECTION.' as they are there',
        '  placement    slug harbour below '.World::SECTION.': placement '.World::PLACEMENT.' of entry '.World::ENTRY.', type '.World::ARTICLE.' with URLs, canonical',
        '  visibility   before_window (rung 9) at 2026-03-10T12:00:00.500000Z; stored scheduled, window 2026-03-10T13:00:00.000000Z to 2026-03-10T20:00:00.000000Z; valid until 2026-03-10T13:00:00.000000Z',
        '  canonical    https://north.example/nyheder/harbour (placement '.World::PLACEMENT.'), this URL',
        '  content keys e-'.World::ENTRY.' n-'.World::SECTION,
        '  read at      4711, the read saw every changeset below it',
    ]);
});

it('prints a node without a mount and a placement without the steps after it', function (): void {
    expect(explainLines(new PathExplanation(
        ResolveOutcome::NoPlacement,
        explainSite(),
        new RouteStep(new RequestPath('/nyheder/harbour'), '/nyheder', 'harbour'),
        new NodeStep(NodeId::fromString(World::SECTION), NodeKind::Section),
        placement: new PlacementStep(NodeId::fromString(World::SECTION), new Slug('harbour')),
    )))->toBe([
        '<info>north.example/nyheder/harbour</info> in da: no_placement',
        '  site         north ('.World::NORTH_SITE.'), publishes in da',
        '  route        /nyheder, the longest of /nyheder/harbour, /nyheder, /; the rest is "harbour"',
        '  node         '.World::SECTION.', a section',
        '  placement    slug harbour below '.World::SECTION.': no placement the reader can read',
        '  content keys none',
        '  read at      4711, the read saw every changeset below it',
    ]);
});

it('prints a placement as unreadable unless both the placement and its entry were read', function (?string $placement, ?string $entry): void {
    $step = new PlacementStep(
        NodeId::fromString(World::SECTION),
        new Slug('harbour'),
        $placement === null ? null : PlacementId::fromString($placement),
        $entry === null ? null : EntryId::fromString($entry),
        TypeId::fromString(World::ARTICLE),
    );

    expect(explainLines(new PathExplanation(ResolveOutcome::NoPlacement, explainSite(), placement: $step))[2])
        ->toBe('  placement    slug harbour below '.World::SECTION.': no placement the reader can read');
})->with([
    'no placement' => [null, World::ENTRY],
    'no entry' => [World::PLACEMENT, null],
    'neither' => [null, null],
]);

it('prints a placement whose entry the reader cannot read, and one of a type without URLs', function (?string $type, bool $routable, bool $canonical, string $tail): void {
    $step = new PlacementStep(
        NodeId::fromString(World::SECTION),
        new Slug('harbour'),
        PlacementId::fromString(World::PLACEMENT),
        EntryId::fromString(World::ENTRY),
        $type === null ? null : TypeId::fromString($type),
        $canonical,
        $routable,
    );

    expect(explainLines(new PathExplanation(ResolveOutcome::NotRoutable, explainSite(), placement: $step))[2])
        ->toBe('  placement    slug harbour below '.World::SECTION.': placement '.World::PLACEMENT.' of entry '.World::ENTRY.', '.$tail);
})->with([
    'no type' => [null, true, false, 'whose entry the reader cannot read, not canonical'],
    'not routable' => [World::NOTE, false, true, 'type '.World::NOTE.' without URLs, canonical'],
]);

it('prints the visibility with no window, an open window and no end of validity', function (?TimeWindow $window, ?DateTimeImmutable $validUntil, string $tail): void {
    expect(explainLines(new PathExplanation(ResolveOutcome::NotVisible, explainSite(), visibility: explainVisibility($window, $validUntil)))[2])
        ->toBe('  visibility   before_window (rung 9) at 2026-03-10T12:00:00.500000Z; stored scheduled, window '.$tail);
})->with([
    'no window' => [null, null, 'none'],
    'always' => [TimeWindow::always(), null, 'always to open'],
    'from only' => [new TimeWindow(new DateTimeImmutable('2026-03-10T13:00:00Z')), new DateTimeImmutable('2026-03-10T13:00:00Z'), '2026-03-10T13:00:00.000000Z to open; valid until 2026-03-10T13:00:00.000000Z'],
    'until only' => [new TimeWindow(until: new DateTimeImmutable('2026-03-10T13:00:00Z')), null, 'always to 2026-03-10T13:00:00.000000Z'],
]);

it('prints the canonical placement, without a URL, elsewhere, or none the reader can read', function (CanonicalStep $canonical, string $line): void {
    expect(explainLines(new PathExplanation(ResolveOutcome::Resolved, explainSite(), canonical: $canonical))[2])->toBe($line);
})->with([
    'none' => [new CanonicalStep(null, 'https://north.example/x', true), '  canonical    no canonical placement the reader can read'],
    'no URL' => [new CanonicalStep(PlacementId::fromString(World::PLACEMENT), null, false), '  canonical    no URL: its node has no route or its site is not configured (placement '.World::PLACEMENT.'), not this URL'],
    'elsewhere' => [new CanonicalStep(PlacementId::fromString(World::SOUTH_PLACEMENT), 'https://south.example/nationalt/harbour', false), '  canonical    https://south.example/nationalt/harbour (placement '.World::SOUTH_PLACEMENT.'), not this URL'],
]);

it('writes the content keys, the explanation and the position as one JSON document', function (): void {
    $explanation = new PathExplanation(ResolveOutcome::UnknownHost, explainSite(null, null));
    $keys = [DependencyKey::entry(EntryId::fromString(World::ENTRY))];

    $answer = explainPrinter()->of(explainRead($explanation, $keys), true);
    $expected = sprintf(
        '{"content_keys":["e-%s"],"explanation":%s,"read_position":"4711"}',
        World::ENTRY,
        new PathExplanationCodecV1()->encode($explanation, ClassificationAccess::Public),
    );

    expect($answer->exit)->toBe(ExitCode::Ok)
        ->and($answer->errors)->toBe([])
        ->and($answer->output)->toBe([$expected]);
});

it('prints every error of a rejected read once, the first first', function (): void {
    $read = QueryResult::rejected(
        new CatalogError(ErrorCode::QueryOverBudget, null, 'The read costs too much.'),
        new CatalogError(ErrorCode::Unauthorized, null, 'Not for you.'),
    );

    $answer = explainPrinter()->of($read, false);

    expect($answer->exit)->toBe(ErrorCode::QueryOverBudget->entry()->exit)
        ->and($answer->output)->toBe([])
        ->and($answer->errors)->toBe([
            'query_over_budget: The read costs too much.',
            'unauthorized: Not for you.',
            sprintf('See %s.', ErrorCode::QueryOverBudget->docs()),
        ]);
});

it('exits with a software error when the answer is not a resolved path', function (): void {
    $answer = explainPrinter()->of(QueryResult::answered(new ProbeCount(1), [], new CommitPosition(EXPLAIN_POSITION)), false);

    expect($answer->exit)->toBe(ExitCode::Software)
        ->and($answer->output)->toBe([])
        ->and($answer->errors)->toBe(['path.resolve answered with something other than a resolved path.']);
});
