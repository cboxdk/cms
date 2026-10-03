<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * The props of notes.login.notice@1, a point of the panel build's host fixture.
 */
#[Stable]
#[PanelPoint(name: 'notes.login.notice', version: 1, kind: PointKind::Data, page: 'login', since: '1.0', label: 'fixture.points.note_login_notice')]
final readonly class NoteLoginNoticeV1 {}
