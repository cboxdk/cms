<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\CreateEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandEncoder;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;

// A caller sends entry.create as JSON: the entry's id, its type, its home node and the fields of
// its first revision by handle. The generated codec of entry.create.v1.json reads it into the
// command and writes it back as canonical JSON, and every surface describes the command with the
// schema the codec carries.

it('reads entry.create from JSON and writes it back as canonical JSON', function (): void {
    $codec = new CreateEntryCodecV1;
    $command = $codec->decode(
        '{"type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01",'
        .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","fields":{"headline":"Harbour opens","ext":{"seo":{"keywords":["harbour"]}}}}',
        ClassificationAccess::Public,
    );

    expect($command)->toBeInstanceOf(CreateEntry::class)
        ->and($command->fields->own->get(new FieldHandle('headline')))->toEqual(new TextValue('Harbour opens'))
        ->and($codec)->toBeInstanceOf(CommandEncoder::class)
        ->and($codec->encodeCommand($command))->toBe($codec->encode($command, ClassificationAccess::Sensitive))
        ->and($codec->encode($command, ClassificationAccess::Public))->toBe(
            '{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","fields":{"ext":{"seo":{"keywords":["harbour"]}},"headline":"Harbour opens"},'
            .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}',
        );
});

it('refuses a document that breaks the schema, and lists the codec of each kernel command', function (): void {
    $commands = array_map(static fn (CommandCodec $codec): string => $codec->command->value.' v'.$codec->version, KernelCommandCodecs::all());

    expect(static fn (): CreateEntry => new CreateEntryCodecV1()->decode(
        '{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","fields":{"Headline":"x"},"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}',
        ClassificationAccess::Public,
    ))->toThrow(DecodingFailed::class, 'is not a field handle')
        ->and($commands)->toContain('entry.create v1', 'entry.publish v1', 'actor.deactivate v1');
});
