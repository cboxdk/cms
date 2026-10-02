<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Attributes\Command as CommandAttribute;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Pipeline\Domain\CommandEncoder;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Tests\Support\SurfaceContract\SampleDocument;
use ReflectionClass;
use stdClass;

/*
 * The generated codecs of the kernel's commands (GUARDRAILS 2.2): each names the command and the
 * version its command class declares with #[Command], its encoder writes that command and no
 * other, and it refuses a whole number below the minimum of its schema with the schema's rule.
 */

/**
 * The command class of each kernel command, by name.
 *
 * @return array<string, class-string<Command>>
 */
function kernelCommandClasses(): array
{
    return [
        'actor.activate' => ActivateActor::class,
        'actor.deactivate' => DeactivateActor::class,
        'actor.register' => RegisterActor::class,
        'entry.create' => CreateEntry::class,
        'entry.publish' => PublishEntry::class,
        'entry.revise' => ReviseEntry::class,
        'entry.unpublish' => UnpublishEntry::class,
        'grant.assign' => AssignGrant::class,
        'grant.revoke' => RevokeGrant::class,
        'placement.create' => CreatePlacement::class,
        'placement.set_window' => SetPlacementWindow::class,
        'variant.release' => ReleaseVariant::class,
    ];
}

it('gives each kernel command its codec at the version its class declares, and the class that version', function (): void {
    $declared = [];

    foreach (kernelCommandClasses() as $name => $class) {
        $attribute = new ReflectionClass($class)->getAttributes(CommandAttribute::class)[0]->newInstance();
        $declared[$name] = [$attribute->name, $attribute->version];
    }

    $codecs = [];

    foreach (KernelCommandCodecs::all() as $codec) {
        $codecs[$codec->command->value] = [$codec->command->value, $codec->version];
    }

    ksort($codecs);

    expect($codecs)->toBe($declared)
        ->and(array_values(array_unique(array_map(static fn (array $pair): int => $pair[1], $declared))))->toBe([1]);
});

it('refuses to encode a command of another class', function (CommandCodec $codec): void {
    $other = new readonly class implements Command {};
    $encoder = $codec->codec;

    expect($encoder)->toBeInstanceOf(CommandEncoder::class)
        ->and(static fn (): string => $encoder instanceof CommandEncoder ? $encoder->encodeCommand($other) : '')
        ->toThrow(EncodingFailed::class, ' is not a '.new ReflectionClass(kernelCommandClasses()[$codec->command->value])->getShortName());
})->with(static fn (): array => array_combine(
    array_map(static fn (CommandCodec $codec): string => $codec->command->value, KernelCommandCodecs::all()),
    array_map(static fn (CommandCodec $codec): array => [$codec], KernelCommandCodecs::all()),
));

it('encodes the command it decoded, and refuses each whole number below its minimum by name', function (CommandCodec $codec): void {
    $encoder = $codec->codec;
    $sample = SampleDocument::of($codec->schema);
    $command = $codec->codec->decode(SampleDocument::json($sample), ClassificationAccess::Sensitive);

    expect($encoder)->toBeInstanceOf(CommandEncoder::class);

    if ($encoder instanceof CommandEncoder) {
        expect($codec->codec->decode($encoder->encodeCommand($command), ClassificationAccess::Sensitive))->toEqual($command);
    }

    $schema = json_decode($codec->schema->json, false, 64, JSON_THROW_ON_ERROR);
    expect($schema)->toBeInstanceOf(stdClass::class);
    $schema = $schema instanceof stdClass ? $schema : new stdClass;
    $definitions = $schema->{'$defs'} ?? new stdClass;

    foreach ((array) ($schema->properties ?? []) as $property => $definition) {
        $reference = is_object($definition) ? ($definition->{'$ref'} ?? null) : null;
        $resolved = is_string($reference) && $definitions instanceof stdClass ? ($definitions->{substr($reference, strlen('#/$defs/'))} ?? null) : $definition;

        if (! is_object($resolved) || ($resolved->type ?? null) !== 'integer' || ! is_int($resolved->minimum ?? null) || ! property_exists($sample, (string) $property)) {
            continue;
        }

        $below = clone $sample;
        $below->{$property} = $resolved->minimum - 1;

        expect(static fn (): object => $codec->codec->decode(SampleDocument::json($below), ClassificationAccess::Sensitive))
            ->toThrow(DecodingFailed::class, sprintf('%s: is %d, less than the minimum %d.', $property, $resolved->minimum - 1, $resolved->minimum));
    }
})->with(static fn (): array => array_combine(
    array_map(static fn (CommandCodec $codec): string => $codec->command->value, KernelCommandCodecs::all()),
    array_map(static fn (CommandCodec $codec): array => [$codec], KernelCommandCodecs::all()),
));
