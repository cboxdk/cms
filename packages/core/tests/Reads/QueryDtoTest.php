<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;

/*
 * The query pipeline's DTOs: the budget of each principal, and the read audit's records, which name
 * each field once, sorted, and never nothing.
 */

it('gives an actor the actor budget and the anonymous principal the anonymous one', function (): void {
    $settings = new QuerySettings(new QueryCost(2), new QueryCost(9));

    expect($settings->budgetOf(new AnonymousPrincipal)->units)->toBe(2)
        ->and($settings->budgetOf(new ActorPrincipal(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c1'), [], IssuerKind::Service, ClassificationAccess::Public))->units)->toBe(9);
});

it('names each audited field once, sorted', function (): void {
    $read = new AuditedRead(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'), ['memo', 'ext.probe.code', 'memo', 'diagnosis'], ClassificationAccess::Sensitive);

    expect($read->fields)->toBe(['diagnosis', 'ext.probe.code', 'memo']);
});

it('refuses an audited read without fields and a record without reads', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1');

    expect(fn (): AuditedRead => new AuditedRead($entry, [], ClassificationAccess::Sensitive))
        ->toThrow(InvalidQueryCall::class, 'The read audit of the entry 01936f5e-8a2b-7c3d-9e4f-0000000000e1 names no field.')
        ->and(fn (): ReadAuditRecord => new ReadAuditRecord(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c1'), new CommandName('probe.read'), 1, new CommitPosition('1'), []))
        ->toThrow(InvalidQueryCall::class, 'The read audit of the query probe.read names no entry.');
});
