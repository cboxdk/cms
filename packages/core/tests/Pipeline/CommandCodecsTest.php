<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeCodec;
use InvalidArgumentException;

/*
 * The codecs an exposed surface reads commands with (GUARDRAILS 2.1, 2.2): one per name and
 * version, registered under CommandCodecs::TAG, which the core's service provider collects.
 */

it('gives the codec of each version of a command', function (): void {
    $first = new CommandCodec(new CommandName('probe.rename'), 1, new RenameProbeCodec);
    $second = new CommandCodec(new CommandName('probe.rename'), 2, new RenameProbeCodec);
    $codecs = new CommandCodecs($first, $second);

    expect($codecs->for(new CommandName('probe.rename'), 1))->toBe($first)
        ->and($codecs->for(new CommandName('probe.rename'), 2))->toBe($second);
});

it('refuses a version no codec reads, naming the command and the tag', function (): void {
    expect(static fn (): CommandCodec => new CommandCodecs(ExposedWorld::codec())->for(new CommandName('probe.rename'), 2))
        ->toThrow(UnknownCommand::class, 'No codec reads version 2 of the command probe.rename, so no exposed surface can read it. Tag its generated codec with CommandCodecs::TAG.');
});

it('refuses two codecs for one version of a command', function (): void {
    expect(static fn (): CommandCodecs => new CommandCodecs(ExposedWorld::codec(), ExposedWorld::codec()))
        ->toThrow(InvalidArgumentException::class, 'Two codecs are registered for version 1 of the command probe.rename.');
});

it('refuses a codec for version 0', function (): void {
    expect(static fn (): CommandCodec => new CommandCodec(new CommandName('probe.rename'), 0, new RenameProbeCodec))
        ->toThrow(UnknownCommand::class, 'The command probe.rename has version 0. Versions start at 1.');
});

it('collects the codecs the container has under the tag', function (): void {
    app()->bind('probe.rename.codec', static fn (): CommandCodec => ExposedWorld::codec());
    app()->tag(['probe.rename.codec'], CommandCodecs::TAG);

    expect(app(CommandCodecs::class)->for(new CommandName('probe.rename'), 1)->codec)->toBeInstanceOf(RenameProbeCodec::class);
});
