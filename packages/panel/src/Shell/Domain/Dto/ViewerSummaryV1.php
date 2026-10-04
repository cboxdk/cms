<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\Shell\Domain\Shell;

/**
 * The props of shell.user-menu@1, the actions of the viewer's menu in the panel's shell (PRD
 * 13.4, section 3.3 of the panel extension architecture): the viewer, as an ActionContribution to
 * it prefills its command from them: the viewer's actor id and the kind of credential the viewer
 * signed in with. An action is shown to a viewer who holds its command's permission.
 */
#[Experimental]
#[PanelPoint(name: Shell::USER_MENU, version: 1, kind: PointKind::Action, page: Shell::PAGE, since: '1.0', label: 'panel.points.shell_user_menu')]
final readonly class ViewerSummaryV1
{
    public function __construct(
        public ActorId $actor,
        public IssuerKind $issuer,
    ) {}
}
