<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\InvalidGrant;

/*
 * The access compiler (PRD 5.10, 12.2): an actor's grants into disjoint access regions with their
 * exceptions, where a deny beats the allow it inherits and the most specific grant wins, and the
 * classification access from the ceilings of the roles that apply, capped by the credential.
 */

const DESK = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01';

const LEGAL = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a02';

function compilerPrincipal(ClassificationAccess $ceiling = ClassificationAccess::Sensitive): ActorPrincipal
{
    return new ActorPrincipal(ActorId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'), [], IssuerKind::Service, $ceiling);
}

/**
 * @param  list<string>|null  $locales
 */
function grantOn(string $path, GrantEffect $effect = GrantEffect::Allow, string $role = DESK, ClassificationAccess $ceiling = ClassificationAccess::Internal, ?array $locales = null): Grant
{
    return new Grant(
        RoleId::fromString($role),
        $ceiling,
        new NodePath($path),
        $effect,
        $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
    );
}

/**
 * The regions as "path -exception -exception", in order.
 *
 * @param  list<Grant>  $grants
 * @return list<string>
 */
function regionsOf(array $grants, ?ActorPrincipal $principal = null): array
{
    return array_map(
        static fn (AccessRegion $region): string => implode(' -', [$region->path->value, ...array_map(static fn (NodePath $path): string => $path->value, $region->exceptions)]),
        new AccessCompiler()->compile($principal ?? compilerPrincipal(), $grants)->regions,
    );
}

/**
 * @param  list<Grant>  $grants
 */
function classificationOf(array $grants, ClassificationAccess $ceiling = ClassificationAccess::Sensitive): ClassificationAccess
{
    return new AccessCompiler()->compile(compilerPrincipal($ceiling), $grants)->classificationAccess;
}

it('gives an actor without grants no regions and public access', function (): void {
    $context = new AccessCompiler()->compile(compilerPrincipal(), []);

    expect($context->regions)->toBe([])
        ->and($context->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and($context->principal)->toEqual(compilerPrincipal());
});

it('reaches the subtree of an allow, and folds an allow below another of the same reach into it', function (): void {
    expect(regionsOf([grantOn('r.news')]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news'), grantOn('r.news.sport')]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news.sport'), grantOn('r.news')]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news'), grantOn('r.culture')]))->toBe(['r.culture', 'r.news']);
});

it('lets a deny beat the allow it inherits, and a more specific allow below the deny reach again', function (): void {
    $grants = [
        grantOn('r.news'),
        grantOn('r.news.sport', GrantEffect::Deny),
        grantOn('r.news.sport.football'),
        grantOn('r.news.sport.football.youth', GrantEffect::Deny),
    ];
    $context = new AccessCompiler()->compile(compilerPrincipal(), $grants);

    expect(regionsOf($grants))->toBe(['r.news -r.news.sport', 'r.news.sport.football -r.news.sport.football.youth'])
        ->and($context->reaches(new NodePath('r.news.local')))->toBeTrue()
        ->and($context->reaches(new NodePath('r.news.sport.golf')))->toBeFalse()
        ->and($context->reaches(new NodePath('r.news.sport.football.league')))->toBeTrue()
        ->and($context->reaches(new NodePath('r.news.sport.football.youth.u12')))->toBeFalse()
        ->and(regionsOf(array_reverse($grants)))->toBe(regionsOf($grants));
});

it('drops a deny that nothing above allows, and one below another deny', function (): void {
    expect(regionsOf([grantOn('r.news', GrantEffect::Deny)]))->toBe([])
        ->and(regionsOf([grantOn('r.news'), grantOn('r.news.sport', GrantEffect::Deny), grantOn('r.news.sport.golf', GrantEffect::Deny)]))->toBe(['r.news -r.news.sport'])
        ->and(regionsOf([grantOn('r.news'), grantOn('r.culture.music', GrantEffect::Deny)]))->toBe(['r.news']);
});

it('lets a deny win over an allow of the same role on the same node, in either order', function (): void {
    expect(regionsOf([grantOn('r.news'), grantOn('r.news', GrantEffect::Deny)]))->toBe([])
        ->and(regionsOf([grantOn('r.news', GrantEffect::Deny), grantOn('r.news')]))->toBe([])
        ->and(regionsOf([grantOn('r'), grantOn('r.news'), grantOn('r.news', GrantEffect::Deny)]))->toBe(['r -r.news']);
});

it('keeps each role\'s deny to that role, so another role\'s allow still reaches', function (): void {
    expect(regionsOf([grantOn('r.news'), grantOn('r.news.sport', GrantEffect::Deny), grantOn('r.news.sport', role: LEGAL)]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news'), grantOn('r.news.sport', GrantEffect::Deny), grantOn('r.news.sport.golf', role: LEGAL)]))->toBe(['r.news -r.news.sport', 'r.news.sport.golf'])
        ->and(regionsOf([grantOn('r.news', GrantEffect::Deny, LEGAL), grantOn('r.news')]))->toBe(['r.news']);
});

it('keeps a node reached through the locales a deny does not name', function (): void {
    expect(regionsOf([grantOn('r.news'), grantOn('r.news.sport', GrantEffect::Deny, locales: ['da'])]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news', locales: ['da']), grantOn('r.news.sport', GrantEffect::Deny, locales: ['da'])]))->toBe(['r.news -r.news.sport'])
        ->and(regionsOf([grantOn('r.news', locales: ['da']), grantOn('r.news.sport', GrantEffect::Deny, locales: ['en'])]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news', locales: ['da', 'en']), grantOn('r.news.sport', GrantEffect::Deny, locales: ['da'])]))->toBe(['r.news'])
        ->and(regionsOf([grantOn('r.news', locales: ['da']), grantOn('r.news.sport', GrantEffect::Deny)]))->toBe(['r.news -r.news.sport'])
        ->and(regionsOf([grantOn('r.news', locales: ['da']), grantOn('r.news', GrantEffect::Deny, locales: ['en'])]))->toBe(['r.news']);
});

it('gives on each node the highest ceiling among the roles that reach it, the lowest of those over the nodes, capped by the credential\'s ceiling', function (): void {
    expect(classificationOf([grantOn('r.news')]))->toBe(ClassificationAccess::Internal)
        ->and(classificationOf([grantOn('r.news'), grantOn('r.news', role: LEGAL, ceiling: ClassificationAccess::Personal)]))->toBe(ClassificationAccess::Personal)
        ->and(classificationOf([grantOn('r.news', role: LEGAL, ceiling: ClassificationAccess::Personal), grantOn('r', ceiling: ClassificationAccess::Public), grantOn('r.culture', role: LEGAL, ceiling: ClassificationAccess::Personal)]))->toBe(ClassificationAccess::Public)
        ->and(classificationOf([grantOn('r.news'), grantOn('r', role: LEGAL, ceiling: ClassificationAccess::Personal)]))->toBe(ClassificationAccess::Personal)
        ->and(classificationOf([grantOn('r.news'), grantOn('r.news', role: LEGAL, ceiling: ClassificationAccess::Personal)], ClassificationAccess::Confidential))->toBe(ClassificationAccess::Confidential)
        ->and(classificationOf([grantOn('r.news'), grantOn('r.culture', GrantEffect::Deny, LEGAL, ClassificationAccess::Sensitive)]))->toBe(ClassificationAccess::Internal)
        ->and(classificationOf([grantOn('r.culture', role: LEGAL, ceiling: ClassificationAccess::Sensitive), grantOn('r.culture', GrantEffect::Deny, LEGAL, ClassificationAccess::Sensitive)]))->toBe(ClassificationAccess::Public)
        ->and(classificationOf([grantOn('r.culture', role: LEGAL, ceiling: ClassificationAccess::Sensitive, locales: ['da']), grantOn('r.culture', GrantEffect::Deny, LEGAL, ClassificationAccess::Sensitive, ['en'])]))->toBe(ClassificationAccess::Sensitive)
        ->and(classificationOf([grantOn('r.news', ceiling: ClassificationAccess::Public)]))->toBe(ClassificationAccess::Public);
});

it('never lifts the access on the nodes of one role by the ceiling another role has on other nodes', function (): void {
    expect(classificationOf([grantOn('r.site1', ceiling: ClassificationAccess::Public), grantOn('r.site2.small', role: LEGAL, ceiling: ClassificationAccess::Sensitive)]))->toBe(ClassificationAccess::Public)
        ->and(classificationOf([grantOn('r.site2.small', role: LEGAL, ceiling: ClassificationAccess::Sensitive), grantOn('r.site1', ceiling: ClassificationAccess::Public)]))->toBe(ClassificationAccess::Public)
        ->and(classificationOf([grantOn('r.news'), grantOn('r.culture', role: LEGAL, ceiling: ClassificationAccess::Personal)]))->toBe(ClassificationAccess::Internal)
        ->and(classificationOf([grantOn('r', role: LEGAL, ceiling: ClassificationAccess::Sensitive), grantOn('r.news', GrantEffect::Deny, LEGAL, ClassificationAccess::Sensitive), grantOn('r.news', ceiling: ClassificationAccess::Public)]))->toBe(ClassificationAccess::Public)
        ->and(classificationOf([grantOn('r', role: LEGAL, ceiling: ClassificationAccess::Sensitive), grantOn('r.news', GrantEffect::Deny, LEGAL, ClassificationAccess::Sensitive), grantOn('r.news.sport', role: LEGAL, ceiling: ClassificationAccess::Sensitive)]))->toBe(ClassificationAccess::Sensitive)
        ->and(classificationOf([grantOn('r', role: LEGAL, ceiling: ClassificationAccess::Sensitive, locales: ['da']), grantOn('r.news', ceiling: ClassificationAccess::Public, locales: ['en'])]))->toBe(ClassificationAccess::Sensitive);
});

it('returns a context the contract accepts, whose reach matches the grants node by node', function (): void {
    $grants = [
        grantOn('r'),
        grantOn('r.a', GrantEffect::Deny),
        grantOn('r.a.b'),
        grantOn('r.a.b.c', GrantEffect::Deny),
        grantOn('r.a.b.c.d', role: LEGAL),
        grantOn('r.e', GrantEffect::Deny, LEGAL),
        grantOn('r.e.f', GrantEffect::Deny),
    ];
    $context = new AccessCompiler()->compile(compilerPrincipal(), $grants);
    $reached = [];

    foreach (['r', 'r.a', 'r.a.x', 'r.a.b', 'r.a.b.y', 'r.a.b.c', 'r.a.b.c.z', 'r.a.b.c.d', 'r.a.b.c.d.w', 'r.e', 'r.e.f', 'r.e.f.g', 'q'] as $path) {
        $reached[$path] = $context->reaches(new NodePath($path));
    }

    expect($context)->toBeInstanceOf(AccessContext::class)
        ->and($reached)->toBe([
            'r' => true, 'r.a' => false, 'r.a.x' => false, 'r.a.b' => true, 'r.a.b.y' => true, 'r.a.b.c' => false, 'r.a.b.c.z' => false,
            'r.a.b.c.d' => true, 'r.a.b.c.d.w' => true, 'r.e' => true, 'r.e.f' => false, 'r.e.f.g' => false, 'q' => false,
        ])
        ->and(regionsOf($grants))->toBe(['r -r.a -r.e.f', 'r.a.b -r.a.b.c', 'r.a.b.c.d']);
});

it('refuses a grant with an empty locale set or one that names a locale twice, and holds a grant in its locales', function (): void {
    $grant = grantOn('r.news', locales: ['da', 'en-GB']);

    expect(fn (): Grant => grantOn('r.news', locales: []))->toThrow(InvalidGrant::class, 'grant on r.news is null for every locale or names at least one')
        ->and(fn (): Grant => grantOn('r.news', locales: ['da', 'DA']))->toThrow(InvalidGrant::class, 'grant on r.news names da twice')
        ->and($grant->holdsIn(new Locale('da')))->toBeTrue()
        ->and($grant->holdsIn(new Locale('en-gb')))->toBeTrue()
        ->and($grant->holdsIn(new Locale('en')))->toBeFalse()
        ->and($grant->holdsIn(null))->toBeFalse()
        ->and(grantOn('r.news')->holdsIn(null))->toBeTrue()
        ->and(grantOn('r.news')->holdsIn(new Locale('sv')))->toBeTrue();
});
