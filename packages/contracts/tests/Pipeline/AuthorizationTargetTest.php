<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;

/*
 * Two authorization targets are equal when they name the same node and the same locale, or the
 * same node and no locale.
 */

it('compares the node and the locale of two targets', function (?string $locale, ?string $other, bool $equal): void {
    $node = '01936f5e-8a2b-7c3d-9e4f-000000000101';
    $target = new AuthorizationTarget(NodeId::fromString($node), $locale === null ? null : new Locale($locale));

    expect($target->equals(new AuthorizationTarget(NodeId::fromString($node), $other === null ? null : new Locale($other))))->toBe($equal)
        ->and($target->equals(new AuthorizationTarget(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000102'), $other === null ? null : new Locale($other))))->toBeFalse();
})->with([
    'no locale on either' => [null, null, true],
    'the same locale' => ['da', 'da', true],
    'two locales' => ['da', 'en', false],
    'a locale and none' => ['da', null, false],
    'none and a locale' => [null, 'da', false],
]);
