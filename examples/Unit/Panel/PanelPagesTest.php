<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\WhoAmICodecV1;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1;
use Cbox\Cms\Panel\Boundary\Generated\Points\AccountMeSectionsCodecV1;

// A module registers a page in the panel's navigation with a nav entry to shell.nav@1: the entry
// names one of the panel's own pages and, in its scope, the permission the viewer must hold on
// some node to see it; the server decides per viewer. The who-am-I page reads actor.me, a query
// every actor may run without a permission (ActorQuery), whose result carries the subject's own
// profile whatever the reader's classification access, so a person always sees their own name and
// email. The page's sections point hands an addon's section the viewer's actor id and nothing more.

const EXAMPLE_ME = '{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","class":"staff","grants":[{"effect":"allow","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03",'
    .'"locales":["da"],"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04","role":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a05","role_handle":"desk","version":1}],'
    .'"profile":{"display_name":"Ada Byline","email":"ada@example.com"},"state":"active","version":1}';

it('registers a page in the navigation with the permission its viewer must hold', function (): void {
    $entry = new NavContribution(new ContributionId('cms.grants'), 'shell.nav@1', 'panel.nav.grants', 'access.grants', null, 200, new Scope(requires: new CommandName('grant.list')));

    expect($entry->kind())->toBe(PointKind::Nav)
        ->and($entry->page)->toBe('access.grants')
        ->and($entry->scope->requires?->value)->toBe('grant.list')
        ->and($entry->runsCode())->toBeFalse();
});

it('reads who am I without input, and writes the subject s own profile at every access', function (): void {
    $query = new WhoAmICodecV1()->decode('{}', ClassificationAccess::Public);
    $codec = new ActorMeCodecV1;
    $me = $codec->decode(EXAMPLE_ME, ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(WhoAmI::class)
        ->and($query)->toBeInstanceOf(ActorQuery::class)
        ->and($me)->toBeInstanceOf(ActorMe::class)
        ->and($me->profile?->email->value)->toBe('ada@example.com')
        ->and($codec->encode($me, ClassificationAccess::Public))->toBe(EXAMPLE_ME)
        ->and($codec->encode($me, ClassificationAccess::Sensitive))->toBe(EXAMPLE_ME);
});

it('hands the sections of the who-am-I page the viewer s actor id as their props', function (): void {
    $props = new AccountMeSectionsV1(ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'));

    expect(new AccountMeSectionsCodecV1()->encode($props, ClassificationAccess::Public))->toBe('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"}');
});
