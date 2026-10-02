<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GrantListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListNodesCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;

// The panel's pickers read with the listing queries: a page at a time, after the id of the last
// row the caller has. A profile is personal data, so the result's codec writes it only for a
// reader whose classification access allows personal, and refuses it in a document for anyone else.

const EXAMPLE_GRANTS = '{"grants":[{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","effect":"allow","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03",'
    .'"locales":null,"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04","node_label":"north/news","profile":{"display_name":"Ada Byline",'
    .'"email":"ada@example.com"},"role":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a05","role_handle":"desk","version":1}],"next":null}';

it('reads a page of nodes from an empty document, and refuses a limit above 100', function (): void {
    $codec = new ListNodesCodecV1;
    $query = $codec->decode('{}', ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ListNodes::class)
        ->and([$query->after, $query->limit])->toBe([null, 50])
        ->and($codec->encode(new ListNodes(limit: 20), ClassificationAccess::Public))->toBe('{"after":null,"limit":20}')
        ->and(static fn (): ListNodes => $codec->decode('{"limit":101}', ClassificationAccess::Public))->toThrow(DecodingFailed::class);
});

it('writes a grant\'s profile only for a reader whose access allows personal', function (): void {
    $codec = new GrantListCodecV1;
    $grants = $codec->decode(EXAMPLE_GRANTS, ClassificationAccess::Personal);
    $queries = array_map(static fn (QueryCodec $each): string => $each->name->value, KernelQueryCodecs::all());

    expect($grants)->toBeInstanceOf(GrantList::class)
        ->and($codec->encode($grants, ClassificationAccess::Personal))->toBe(EXAMPLE_GRANTS)
        ->and($codec->encode($grants, ClassificationAccess::Internal))->toContain('"profile":{}')
        ->and($grants->visibleTo(ClassificationAccess::Internal)->grants[0]->profile?->email)->toBe(Omitted::Field)
        ->and(static fn (): GrantList => $codec->decode(EXAMPLE_GRANTS, ClassificationAccess::Internal))->toThrow(DecodingFailed::class)
        ->and($queries)->toContain('actor.list', 'grant.list', 'node.list', 'role.list');
});
