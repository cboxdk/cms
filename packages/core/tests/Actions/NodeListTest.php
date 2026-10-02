<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\NodeListCodecV1;
use Cbox\Cms\Core\Structure\Domain\Dto\NodeList;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;

/*
 * node.list through the query pipeline with fakes (GUARDRAILS 9, PRD 5.8, 5.10): every actor may
 * read the nodes its regions reach, with no role naming node.list, and the anonymous principal is
 * refused as unauthorized. A node holds no personal data, so a reader at public access gets the
 * same document as one at personal access.
 */

it('lists the nodes with their sites and path labels for an actor that holds no permission', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListNodes, $world->reader([], ClassificationAccess::Public));
    $result = $answer->result;

    expect($result instanceof NodeList ? $result->nodes : [])->toEqual(ListingWorld::nodes())
        ->and($world->authorizer->asked)->toHaveCount(1)
        ->and($result instanceof NodeList ? new NodeListCodecV1()->encode($result, $answer->access) : '')
        ->toContain('"label":"north/nyheder/section"', '"site_handle":"north"', '"parent":null');
});

it('gives a reader below personal access the same document as one at personal access', function (): void {
    $world = new ListingActionWorld;
    $public = $world->read(new ListNodes, $world->reader([], ClassificationAccess::Public));
    $personal = $world->read(new ListNodes, $world->reader([], ClassificationAccess::Personal));
    $codec = new NodeListCodecV1;

    expect($public->result instanceof NodeList ? $codec->encode($public->result, $public->access) : 'public')
        ->toBe($personal->result instanceof NodeList ? $codec->encode($personal->result, $personal->access) : 'personal');
});

it('pages after a node in tree order', function (): void {
    $world = new ListingActionWorld;
    $result = $world->read(new ListNodes(NodeId::fromString(ListingWorld::ROOT), 1), $world->reader([], ClassificationAccess::Internal))->result;

    expect($result instanceof NodeList ? [$result->nodes[0]->label, $result->next?->toString()] : [])->toBe(['north/nyheder', ListingWorld::NEWS]);
});

it('refuses the anonymous principal as unauthorized', function (): void {
    $answer = new ListingActionWorld()->read(new ListNodes, null);

    expect($answer->result)->toBeNull()
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
});
