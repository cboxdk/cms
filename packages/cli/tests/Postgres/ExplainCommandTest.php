<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Tests\Console\WorkbenchRegistry;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ExplainedPathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use RuntimeException;

/*
 * cms:explain against the workbench on Postgres (GUARDRAILS 5 and 7.1, PRD 5.8, 5.9): the fixture
 * article published below north's section "/nyheder" with the slug "harbour", and the mount
 * "/national" on south of north's section. cms:explain reads path.resolve through the query
 * pipeline of the installation, compiled from the workbench's scan roots, as the anonymous
 * principal, with the sites north and south configured in cbox-cms.sites and the clock an hour
 * after the publish. It explains the placement on north and the mount URL on the second site, whose
 * canonical URL stays on north, and it prints the explanation path.resolve returns, written by the
 * generated PathExplanationCodecV1, the one encoding of it: the document of --json, one line of
 * explained-path.v1.json, holds exactly the encoding of the explanation the query pipeline answers
 * the same read with. The kernel's QueryAuthorizer allows it, because path.resolve is a public read.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const EXPLAINED_ENTRY = '0192a0c0-0000-7000-8000-0000000043e1';

const EXPLAINED_PLACEMENT = '0192a0c0-0000-7000-8000-0000000043c1';

/**
 * The structure of PlacementWorld with the mount "/national" on south of north's section, the
 * fixture article published there as "harbour" with the window given, and the installation set up
 * to resolve on it: the workbench's registry, the configured sites and the clock an hour after the
 * publish.
 */
function explainedWorld(?TimeWindow $window = null): PlacementStructure
{
    $structure = PlacementWorld::seed();
    $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
    $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 943, clock: $clock));
    $mount = $fixtures->mount($structure->south->root, $structure->northSection);
    $fixtures->route($structure->south, new Locale('da'), '/national', $mount);

    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    $entry = EntryId::fromString(EXPLAINED_ENTRY);
    $placement = PlacementId::fromString(EXPLAINED_PLACEMENT);
    $created = $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'explain-entry');
    $placed = $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour', 'explain-place');
    $published = $world->publish($entry, 1, 1, $placement, 1, 'explain-publish', $window);

    if ($created->outcome() !== Outcome::Committed || $placed->outcome() !== Outcome::Committed || $published->outcome() !== Outcome::Committed) {
        throw new LogicException('The article to explain was not published.');
    }

    WorkbenchRegistry::bind();
    app()->instance(Clock::class, new FakeClock(new DateTimeImmutable(EntryWorld::NOW)->modify('+1 hour')));
    config()->set(SitesConfig::CONFIG_KEY, [
        'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en']],
        'south' => ['origin' => 'https://south.example', 'locales' => ['da']],
    ]);

    return $structure;
}

/**
 * @param  array<string, bool|string>  $options
 * @return array{int, string}
 */
function explainCli(string $url, array $options = ['--locale' => 'da']): array
{
    $status = Artisan::call('cms:explain', ['url' => $url, ...$options]);

    return [$status, Artisan::output()];
}

/**
 * @return array<array-key, mixed>
 */
function explainDocument(string $output): array
{
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

/**
 * The position of a read that ran between the reads $before and $after, as cms:explain prints
 * it. The position is the xmin of the read's snapshot (PRD 8.4), and xids belong to the whole
 * Postgres cluster, so another test process's transaction moves it between two reads; a later
 * snapshot's xmin is never lower than an earlier one's, so the read in between lies within them.
 */
function explainedPositionBetween(string $position, QueryResult $before, QueryResult $after): void
{
    $lowest = $before->position->value ?? throw new LogicException('The read before has no position.');
    $highest = $after->position->value ?? throw new LogicException('The read after has no position.');

    expect($position)->toMatch('/\A[1-9][0-9]*\z/')
        ->and((int) $position)->toBeGreaterThanOrEqual((int) $lowest)
        ->and((int) $position)->toBeLessThanOrEqual((int) $highest);
}

/**
 * path.resolve through the installation's query pipeline, as cms:explain reads it.
 */
function explainedRead(string $host, string $path): QueryResult
{
    return app(QueryPipeline::class)->run(new QueryCall(new ResolvePath(new Host($host), new Locale('da'), new RequestPath($path)), null));
}

it('explains the placement on its site, step by step, with its content keys', function (): void {
    $structure = explainedWorld();
    $section = $structure->northSection->id->toString();

    $before = explainedRead('north.example', '/nyheder/harbour');
    [$status, $output] = explainCli('https://north.example/nyheder/harbour?utm_source=mail#top');
    $after = explainedRead('north.example', '/nyheder/harbour');
    $lines = explode("\n", rtrim($output));
    $readAt = array_pop($lines);

    expect($readAt)->toMatch('/\A  read at      [0-9]+, the read saw every changeset below it\z/');
    explainedPositionBetween((string) preg_replace('/\A  read at      ([0-9]+),.*\z/', '$1', $readAt), $before, $after);

    expect($status)->toBe(0)
        ->and($lines)->toBe([
            'north.example/nyheder/harbour in da: resolved',
            sprintf('  site         north (%s), publishes in da', $structure->north->id->toString()),
            '  route        /nyheder, the longest of /nyheder/harbour, /nyheder, /; the rest is "harbour"',
            sprintf('  node         %s, a section', $section),
            sprintf('  placement    slug harbour below %s: placement %s of entry %s, type %s with URLs, canonical', $section, EXPLAINED_PLACEMENT, EXPLAINED_ENTRY, EntryWorld::type(EntryWorld::ARTICLE)->id->toString()),
            '  visibility   visible (rung 11) at 2026-03-10T13:00:00.000000Z; stored live, window 2026-03-10T12:00:00.000000Z to open',
            sprintf('  canonical    https://north.example/nyheder/harbour (placement %s), this URL', EXPLAINED_PLACEMENT),
            sprintf('  content keys e-%s n-%s', EXPLAINED_ENTRY, $section),
        ]);
});

it('explains the mount URL on the second site: the placement below the source, canonical on the source\'s site', function (): void {
    $structure = explainedWorld();
    $section = $structure->northSection->id->toString();

    [$status, $output] = explainCli('https://south.example/national/harbour');
    [$jsonStatus, $json] = explainCli('https://south.example/national/harbour', ['--locale' => 'da', '--json' => true]);
    $document = explainDocument($json);
    $explained = is_array($document['explanation'] ?? null) ? $document['explanation'] : [];
    $node = is_array($explained['node'] ?? null) ? $explained['node']['node'] ?? null : null;

    expect($status)->toBe(0)
        ->and($output)->toStartWith("south.example/national/harbour in da: resolved\n")
        ->and($output)->toContain(sprintf("  site         south (%s), publishes in da\n", $structure->south->id->toString()))
        ->and($output)->toContain('  route        /national, the longest of /national/harbour, /national, /; the rest is "harbour"')
        ->and($output)->toMatch('/  node         [0-9a-f-]{36}, a mount\n/')
        ->and($output)->toContain(sprintf("  mount        shows the placements below %s as they are there\n", $section))
        ->and($output)->toContain(sprintf('  placement    slug harbour below %s: placement %s of entry %s', $section, EXPLAINED_PLACEMENT, EXPLAINED_ENTRY))
        ->and($output)->toContain(sprintf("  canonical    https://north.example/nyheder/harbour (placement %s), not this URL\n", EXPLAINED_PLACEMENT))
        ->and($jsonStatus)->toBe(0)
        ->and($document['content_keys'] ?? null)->toBe(['e-'.EXPLAINED_ENTRY, 'n-'.$section])
        ->and($document['explanation'] ?? null)->toMatchArray([
            'outcome' => 'resolved',
            'mount' => ['mount' => $node, 'source' => $section],
            'canonical' => ['here' => false, 'placement' => EXPLAINED_PLACEMENT, 'url' => 'https://north.example/nyheder/harbour'],
        ]);
});

it('prints the explanation path.resolve answers the same read with, through the generated codecs', function (): void {
    explainedWorld();

    foreach (['north.example' => '/nyheder/harbour', 'south.example' => '/national/harbour'] as $host => $path) {
        $before = explainedRead($host, $path);
        [$status, $json] = explainCli('https://'.$host.$path, ['--locale' => 'da', '--json' => true]);
        $read = explainedRead($host, $path);
        $resolved = $read->result instanceof ResolvedPath ? $read->result : throw new LogicException('The read was not answered with a resolved path.');
        $document = explainDocument($json);
        $position = $document['read_position'] ?? null;

        expect($status)->toBe(0)
            ->and(array_keys($document))->toBe(['content_keys', 'explanation', 'read_position'])
            ->and(substr_count(trim($json), "\n"))->toBe(0)
            ->and(new ExplainedPathCodecV1()->decode(trim($json), ClassificationAccess::Public)->readPosition->value)->toBe($position)
            ->and($document['content_keys'])->toBe(array_map(static fn (DependencyKey $key): string => $key->toString(), $read->contentKeys))
            ->and($document['explanation'])->toBe(json_decode(new PathExplanationCodecV1()->encode($resolved->explanation, ClassificationAccess::Public), true, 512, JSON_THROW_ON_ERROR))
            ->and($position)->toBeString();

        explainedPositionBetween(is_string($position) ? $position : '', $before, $read);
    }
});

it('explains a page that does not resolve and exits 0: a closed window, an unknown slug and an unknown host', function (): void {
    explainedWorld(new TimeWindow(new DateTimeImmutable(EntryWorld::NOW), new DateTimeImmutable(EntryWorld::NOW)->modify('+30 minutes')));

    [$closed, $closedOutput] = explainCli('https://north.example/nyheder/harbour');
    [$missing, $missingOutput] = explainCli('https://north.example/nyheder/pier');
    [$unknown, $unknownOutput] = explainCli('https://west.example/nyheder/harbour', ['--locale' => 'da', '--json' => true]);

    expect($closed)->toBe(0)
        ->and($closedOutput)->toStartWith("north.example/nyheder/harbour in da: not_visible\n")
        ->and($closedOutput)->toContain('  visibility   after_window (rung 9) at 2026-03-10T13:00:00.000000Z; stored live, window 2026-03-10T12:00:00.000000Z to 2026-03-10T12:30:00.000000Z')
        ->and($closedOutput)->not->toContain('  canonical')
        ->and($closedOutput)->toContain('  content keys e-'.EXPLAINED_ENTRY)
        ->and($missing)->toBe(0)
        ->and($missingOutput)->toStartWith("north.example/nyheder/pier in da: no_placement\n")
        ->and($missingOutput)->toContain(': no placement the reader can read')
        ->and($missingOutput)->toContain('  content keys none')
        ->and($unknown)->toBe(0)
        ->and(explainDocument($unknownOutput)['explanation'] ?? null)->toMatchArray(['outcome' => 'unknown_host', 'route' => null, 'site' => [
            'handle' => null, 'host' => 'west.example', 'locale' => 'da', 'locale_published' => false, 'site' => null,
        ]]);
});
