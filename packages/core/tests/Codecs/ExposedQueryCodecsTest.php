<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvedPathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvePathCodecV1;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;

/*
 * Every query action of the compiled registry that a surface exposes has a registered QueryCodec
 * (GUARDRAILS 2.1, 2.2): the codecs composer generate:protocol writes from the kernel's query
 * schemas, which the core registers under QueryCodecs::TAG. A planted query exposed on a surface
 * without a codec is reported; one exposed on no surface, and a kernel query exposed with the
 * kernel's codecs, are not. The twin of ExposedCommandCodecsTest.
 */

/**
 * probe.read, version 2, exposed on the surfaces given.
 *
 * @param  list<Surface>  $surfaces
 */
function plantedRead(array $surfaces): ActionEntry
{
    return new ActionEntry(ReadProbeAction::class, 'acme/probe', ActionKind::Query, new CommandName('probe.read'), 2, ReadProbe::class, $surfaces);
}

/**
 * path.resolve, version 1, exposed on the surfaces given.
 *
 * @param  list<Surface>  $surfaces
 */
function exposedResolve(array $surfaces): ActionEntry
{
    return new ActionEntry(ResolvePathAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('path.resolve'), 1, ResolvePath::class, $surfaces);
}

it('has a codec for every query action the compiled registry exposes on a surface', function (): void {
    expect(ExposedQueryCodecs::missing(app(CompiledRegistry::class)->actions, app(QueryCodecs::class)))->toBe([]);
});

it('reports a query action exposed on a surface without a codec, and not one exposed on none, and a kernel query without the kernel\'s codecs', function (): void {
    $actions = [...app(CompiledRegistry::class)->actions, plantedRead([Surface::Rest, Surface::Mcp]), plantedRead([]), exposedResolve([Surface::Rest])];

    expect(ExposedQueryCodecs::missing($actions, app(QueryCodecs::class)))->toBe(['probe.read v2 on rest, mcp'])
        ->and(ExposedQueryCodecs::missing([exposedResolve([Surface::Rest, Surface::Mcp])], new QueryCodecs))->toBe(['path.resolve v1 on rest, mcp']);
});

it('registers the codec of each of the kernel\'s queries under its name and version, with the codec of its result', function (): void {
    $codec = app(QueryCodecs::class)->find(new CommandName('path.resolve'), 1);

    expect($codec?->name->value)->toBe('path.resolve')
        ->and($codec?->version)->toBe(1)
        ->and($codec?->query)->toBeInstanceOf(ResolvePathCodecV1::class)
        ->and($codec?->result)->toBeInstanceOf(ResolvedPathCodecV1::class)
        ->and($codec?->querySchema->json)->toBe(ResolvePathCodecV1::SCHEMA)
        ->and($codec?->resultSchema->json)->toBe(ResolvedPathCodecV1::SCHEMA);
});
