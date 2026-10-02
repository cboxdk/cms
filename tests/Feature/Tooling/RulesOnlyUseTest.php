<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Allowed\Permitted;
use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Outside\Stranger;
use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target\Sibling;
use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target\UsesSibling;
use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target\UsesStranger;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Closure;
use Pest\Arch\SingleArchExpectation;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;

/*
 * Rules::onlyUse() is the layer rules' toOnlyUse() with each layer made once (tests/Arch/LayersTest.php).
 * These cases hold it to Pest's own toOnlyUse() on the fixtures in tests/Support/Arch/Fixtures/OnlyUse:
 * the same verdict for classes and for namespaces. As in Pest, a target may use what it is
 * allowed and what its own layer holds, not the other targets.
 */

const ONLY_USE_NAMESPACE = 'Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse';

/**
 * Whether the check fails, with the message of its failure.
 *
 * @param  Closure(): void  $check
 */
function onlyUseFailure(Closure $check): ?string
{
    try {
        $check();
    } catch (AssertionFailedError $failure) {
        return $failure->getMessage();
    }

    return null;
}

/**
 * @param  list<string>  $targets
 * @param  list<string>  $allowed
 */
function pestOnlyUseFailure(array $targets, array $allowed): ?string
{
    return onlyUseFailure(static function () use ($targets, $allowed): void {
        $expectation = expect($targets)->toOnlyUse($allowed);

        if ($expectation instanceof SingleArchExpectation) {
            $expectation->ensureLazyExpectationIsVerified();
        }
    });
}

it('gives the verdict of Pest\'s toOnlyUse()',
    /**
     * @param  list<string>  $targets
     * @param  list<string>  $allowed
     */
    function (array $targets, array $allowed, ?string $uses): void {
        $targets = array_values(array_filter($targets, is_string(...)));
        $allowed = array_values(array_filter($allowed, is_string(...)));
        $ours = onlyUseFailure(static fn () => Rules::onlyUse($targets, $allowed));
        $pest = pestOnlyUseFailure($targets, $allowed);

        if ($uses === null) {
            expect($ours)->toBeNull()->and($pest)->toBeNull();

            return;
        }

        expect($ours)->toContain("uses {$uses}.")->and($pest)->toContain("it also uses '{$uses}'");
    })->with([
        'a target that uses an allowed class' => [[Sibling::class], [Permitted::class], null],
        'targets that use each other without allowing it' => [[Sibling::class, UsesSibling::class], [Permitted::class], Sibling::class],
        'a target that uses a class it is allowed as a sibling' => [[UsesSibling::class], [Permitted::class, Sibling::class], null],
        'a target that uses a class outside' => [[UsesStranger::class], [Permitted::class], Stranger::class],
        'a namespace of targets with one that uses a class outside' => [[ONLY_USE_NAMESPACE.'\Target'], [ONLY_USE_NAMESPACE.'\Allowed'], Stranger::class],
        'a namespace of targets that may use the outside namespace' => [[ONLY_USE_NAMESPACE.'\Target'], [ONLY_USE_NAMESPACE.'\Allowed', ONLY_USE_NAMESPACE.'\Outside'], null],
    ]);

it('names every violation, not only the first', function (): void {
    $failure = onlyUseFailure(static fn () => Rules::onlyUse([UsesSibling::class, UsesStranger::class], []));

    expect($failure)->toContain(UsesSibling::class.' ('.UsesSibling::class.') uses '.Sibling::class.'.')
        ->toContain(UsesSibling::class.' ('.UsesSibling::class.') uses '.Permitted::class.'.')
        ->toContain(UsesStranger::class.' ('.UsesStranger::class.') uses '.Stranger::class.'.');
});

it('names the file of each class that breaks the rule', function (): void {
    $failure = onlyUseFailure(static fn () => Rules::onlyUse([UsesStranger::class], [Permitted::class]));
    $file = new ReflectionClass(UsesStranger::class)->getFileName();

    expect($file)->toBeString()
        ->and($failure)->toContain(UsesStranger::class.' ('.UsesStranger::class.') uses '.Stranger::class.'. In '.$file);
});
