<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\LogSequenceNumber;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

// A surface answers a write with its receipt, written by the generated codec of receipt.v1.json:
// the edge has not acknowledged yet, so the call waited for origin and got its position.

it('answers a write with the receipt as canonical JSON', function (): void {
    $receipt = Receipt::committed(
        ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
        WaitLevel::Origin,
        RetentionClass::Standard,
        [
            ProjectionStatus::acknowledged(new ProjectionName('fragments'), new DateTimeImmutable('2026-03-10T12:00:00.125Z')),
            ProjectionStatus::pending(new ProjectionName('edge')),
        ],
        new ConsistencyToken(1, new LogSequenceNumber('0/16B3748')),
    );

    expect(new ReceiptCodecV1()->encode($receipt, ClassificationAccess::Public))->toBe(
        '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","outcome":"committed",'
        .'"position":{"generation":1,"lsn":"0/16B3748"},'
        .'"projections":[{"acknowledged_at":null,"projection":"edge","state":"pending"},'
        .'{"acknowledged_at":"2026-03-10T12:00:00.125000Z","projection":"fragments","state":"acknowledged"}],'
        .'"retention_class":"standard","wait_level":"origin"}',
    );
});

it('reads a receipt a client kept, and refuses one that breaks the contract', function (): void {
    $codec = new ReceiptCodecV1;
    $receipt = $codec->decode(
        '{"changeset_id":null,"outcome":"rejected","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}',
        ClassificationAccess::Public,
    );

    expect($receipt->outcome)->toBe(Outcome::Rejected)
        ->and($receipt->isCommitted())->toBeFalse()
        ->and(static fn (): Receipt => $codec->decode(
            '{"changeset_id":null,"outcome":"committed","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}',
            ClassificationAccess::Public,
        ))->toThrow(DecodingFailed::class, '[json_invalid] breaks a rule of the contract: A committed receipt needs a ChangesetId: the command committed a changeset.');
});
