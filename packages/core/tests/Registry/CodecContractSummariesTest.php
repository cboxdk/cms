<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\CreateRoleCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Adapter\CodecContractSummaries;
use Cbox\Cms\Core\Registry\Domain\ActionKind;

/*
 * The titles and descriptions action.list lists actions by come from the JSON Schemas the
 * registered codecs carry (GUARDRAILS 2.2): a command's from its CommandCodec, a query's from the
 * document schema of its QueryCodec; a contract version without a codec, or whose schema has no
 * title, has none; and a schema without a description has an empty one.
 */

it('reads a command s title and description from its codec s schema, and a query s from its document schema', function (): void {
    $summaries = new CodecContractSummaries(new CommandCodecs(...KernelCommandCodecs::all()), new QueryCodecs(...KernelQueryCodecs::all()));
    $command = $summaries->of(ActionKind::Write, new CommandName('role.create'), 1);
    $query = $summaries->of(ActionKind::Query, new CommandName('actor.me'), 1);

    expect($command?->title)->toBe('role.create, contract version 1')
        ->and($command?->description)->toStartWith('Creates a role (PRD 5.10, 6.4)')
        ->and($query?->title)->toBe('actor.me, contract version 1')
        ->and($query?->description)->toContain('Who am I (PRD 5.16, 13.4)')
        ->and($summaries->of(ActionKind::Query, new CommandName('role.create'), 1))->toBeNull()
        ->and($summaries->of(ActionKind::Write, new CommandName('role.create'), 2))->toBeNull()
        ->and($summaries->of(ActionKind::Write, new CommandName('probe.add'), 1))->toBeNull();
});

it('gives none for a schema without a title, and an empty description for one without a description', function (): void {
    $untitled = new CodecContractSummaries(new CommandCodecs(new CommandCodec(new CommandName('probe.add'), 1, new CreateRoleCodecV1, new JsonSchema('{"type":"object","description":"Adds."}'))), new QueryCodecs);
    $terse = new CodecContractSummaries(new CommandCodecs(new CommandCodec(new CommandName('probe.add'), 1, new CreateRoleCodecV1, new JsonSchema('{"type":"object","title":"probe.add, contract version 1"}'))), new QueryCodecs);

    expect($untitled->of(ActionKind::Write, new CommandName('probe.add'), 1))->toBeNull()
        ->and($terse->of(ActionKind::Write, new CommandName('probe.add'), 1)?->title)->toBe('probe.add, contract version 1')
        ->and($terse->of(ActionKind::Write, new CommandName('probe.add'), 1)?->description)->toBe('');
});
