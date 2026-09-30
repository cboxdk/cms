<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Codecs\Boundary\Generated\UnpublishEntryCodecV1;
use Cbox\Cms\Core\Pipeline\Boundary\CodecCommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;

/*
 * The content hash the core binds (PRD 6.1, GUARDRAILS 2.2): the SHA-256 of the command's name,
 * its version and the canonical JSON its generated codec writes, and a refusal for a command no
 * codec reads or whose codec is not generated, as a hand-written test codec is not.
 */

it('hashes the name, the version and the canonical JSON the command\'s codec writes', function (): void {
    $command = new UnpublishEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000065e1'), new AggregateVersion(4));
    $json = new UnpublishEntryCodecV1()->encode($command, ClassificationAccess::Sensitive);

    expect($json)->toBe('{"entry":"01936f5e-8a2b-7c3d-9e4f-0000000065e1","version":4}')
        ->and(app(CommandContentHasher::class))->toBeInstanceOf(CodecCommandContentHasher::class)
        ->and(app(CommandContentHasher::class)->hash(new CommandName('entry.unpublish'), 1, $command))->toEqual(ContentHash::of("entry.unpublish\n1\n".$json));
});

it('refuses a command whose codec is not a generated CommandEncoder', function (): void {
    $world = new ExposedWorld;
    $hasher = new CodecCommandContentHasher(new CommandCodecs(ExposedWorld::codec()));

    expect(static fn (): ContentHash => $hasher->hash(new CommandName(ExposedWorld::COMMAND), 1, $world->world->command()))
        ->toThrow(UnknownCommand::class);
});

it('refuses a command whose version no codec reads', function (): void {
    $command = new UnpublishEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000065e1'), new AggregateVersion(4));

    expect(static fn (): ContentHash => new CodecCommandContentHasher(new CommandCodecs(UnpublishEntryCodecV1::commandCodec()))->hash(new CommandName('entry.unpublish'), 2, $command))
        ->toThrow(UnknownCommand::class);
});
