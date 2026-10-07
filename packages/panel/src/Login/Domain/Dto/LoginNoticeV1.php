<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\Login\Domain\Login;

/**
 * The props of login.notice@1, the notices above the panel's login form (PRD 13.4, section 3.11
 * of the panel extension architecture): a data point without props. A LoginNotice to it is a
 * translated plain-text notice with a tone, which the login page shows in render order; it is data
 * alone, because no addon code runs on a credential page, so the page writes no addon into its
 * import map and loads nothing of the addon. A notice's scope may require nothing: the login page
 * has no viewer to hold a permission.
 */
#[Experimental]
#[PanelPoint(name: Login::NOTICE, version: 1, kind: PointKind::Data, page: Login::PAGE, since: '1.0', label: 'panel.points.login_notice')]
final readonly class LoginNoticeV1 {}
