<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\InvalidAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;

/*
 * The access context the pipelines consume (PRD 5.10, 6.2, 12.2): a principal, disjoint access
 * regions with their exceptions, and a classification access within the principal's ceiling.
 */

function accessPrincipal(ClassificationAccess $ceiling = ClassificationAccess::Confidential): ActorPrincipal
{
    return new ActorPrincipal(ActorId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'), [], IssuerKind::Agent, $ceiling);
}

it('gives a call without a credential the anonymous context with public access and no regions', function (): void {
    $context = AccessContext::anonymous();

    expect($context->principal)->toBeInstanceOf(AnonymousPrincipal::class)
        ->and($context->regions)->toBe([])
        ->and($context->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and($context->reaches(new NodePath('root')))->toBeFalse();
});

it('refuses a classification access above the principal\'s ceiling', function (): void {
    expect(fn (): AccessContext => new AccessContext(new AnonymousPrincipal, [], ClassificationAccess::Internal))
        ->toThrow(InvalidAccess::class, 'never exceeds its principal\'s ceiling, public, got internal')
        ->and(fn (): AccessContext => new AccessContext(accessPrincipal(), [], ClassificationAccess::Personal))
        ->toThrow(InvalidAccess::class, 'ceiling, confidential, got personal')
        ->and(new AccessContext(accessPrincipal(), [], ClassificationAccess::Internal)->classificationAccess)->toBe(ClassificationAccess::Internal);
});

it('reaches the nodes of its regions except their exceptions', function (): void {
    $context = new AccessContext(accessPrincipal(), [
        new AccessRegion(new NodePath('root.news'), [new NodePath('root.news.sport')]),
        new AccessRegion(new NodePath('root.culture')),
    ], ClassificationAccess::Confidential);

    expect($context->reaches(new NodePath('root.news')))->toBeTrue()
        ->and($context->reaches(new NodePath('root.news.local.east')))->toBeTrue()
        ->and($context->reaches(new NodePath('root.news.sport')))->toBeFalse()
        ->and($context->reaches(new NodePath('root.news.sport.football')))->toBeFalse()
        ->and($context->reaches(new NodePath('root.newsroom')))->toBeFalse()
        ->and($context->reaches(new NodePath('root.culture.music')))->toBeTrue()
        ->and($context->reaches(new NodePath('root')))->toBeFalse();
});

it('refuses regions that overlap', function (): void {
    expect(fn (): AccessContext => new AccessContext(accessPrincipal(), [
        new AccessRegion(new NodePath('root.news')),
        new AccessRegion(new NodePath('root.news.local')),
    ], ClassificationAccess::Public))->toThrow(InvalidAccess::class, 'root.news.local is at or below root.news')
        ->and(fn (): AccessContext => new AccessContext(accessPrincipal(), [
            new AccessRegion(new NodePath('root.news')),
            new AccessRegion(new NodePath('root.news')),
        ], ClassificationAccess::Public))->toThrow(InvalidAccess::class, 'disjoint');
});

it('refuses an exception that is not strictly below the region, or that overlaps another', function (): void {
    expect(fn (): AccessRegion => new AccessRegion(new NodePath('root.news'), [new NodePath('root.news')]))
        ->toThrow(InvalidAccess::class, 'lies below its path root.news, but root.news does not')
        ->and(fn (): AccessRegion => new AccessRegion(new NodePath('root.news'), [new NodePath('root.culture')]))
        ->toThrow(InvalidAccess::class, 'but root.culture does not')
        ->and(fn (): AccessRegion => new AccessRegion(new NodePath('root.news'), [new NodePath('root.news.sport'), new NodePath('root.news.sport.football')]))
        ->toThrow(InvalidAccess::class, 'root.news.sport.football is repeated in or below root.news.sport');
});

it('takes a node path of ltree labels and refuses anything else', function (): void {
    expect(new NodePath('root.news-2026.Local_East')->value)->toBe('root.news-2026.Local_East')
        ->and(new NodePath(str_repeat('a', 1000))->value)->toHaveLength(1000)
        ->and(new NodePath('root')->contains(new NodePath('root.news')))->toBeTrue()
        ->and(new NodePath('root')->isAbove(new NodePath('root')))->toBeFalse()
        ->and(new NodePath('root.news')->equals(new NodePath('root.news')))->toBeTrue();

    foreach (['', '.', 'root.', '.root', 'root..news', 'root news', 'rød', str_repeat('a', 1001)] as $invalid) {
        expect(fn (): NodePath => new NodePath($invalid))->toThrow(InvalidAccess::class, 'A node path is labels');
    }
});
