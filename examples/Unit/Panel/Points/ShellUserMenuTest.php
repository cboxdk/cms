<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Points;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Panel\Boundary\Generated\Points\ViewerSummaryCodecV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;
use Examples\Unit\Build\BuildTestCase;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * shell.user-menu@1 in the workbench: cms:build compiles the fixture addon's ActionContribution
 * fixtureaddon.new-article, a button of the viewer's menu that opens the form of entry.create, a
 * command in the addon's issues. An action runs no code: the host renders the button from its
 * data, shows it only to a viewer who may run the command, and hands it the viewer's actor id and
 * issuer as the point's props, for a prefill by JSON pointer.
 */
final class ShellUserMenuTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_addon_s_action_with_its_command(): void
    {
        self::assertSame(0, $this->build());
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'shell.user-menu@1', '--json' => true]));

        $document = json_decode(app(Kernel::class)->output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['fills']);
        self::assertSame(['fixtureaddon.new-article'], array_column($document['fills'], 'contribution'));
        self::assertSame(['action'], array_column($document['fills'], 'kind'));
        self::assertSame(['entry.create@1'], array_column($document['fills'], 'command'));

        $viewer = new ViewerSummaryV1(ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'), IssuerKind::Human);
        self::assertSame('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","issuer":"human"}', new ViewerSummaryCodecV1()->encode($viewer, ClassificationAccess::Public));
    }
}
