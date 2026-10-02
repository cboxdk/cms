<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;

/*
 * A refused routing value is shown in the message as at most 100 characters of valid UTF-8, with
 * "..." after a value that was cut, so a message never carries a long or broken value.
 */

it('shows a value of 100 characters in full and cuts a longer one after 100, counting characters, not bytes', function (): void {
    $hundred = str_repeat('æ', 100);

    expect(InvalidRoutingValue::siteHandle($hundred)->getMessage())->toEndWith("got \"{$hundred}\".")
        ->and(InvalidRoutingValue::siteHandle($hundred.'ø')->getMessage())->toEndWith("got \"{$hundred}...\".")
        ->and(InvalidRoutingValue::origin('x')->getMessage())->toEndWith('got "x".');
});

it('shows invalid UTF-8 as valid UTF-8', function (): void {
    $message = InvalidRoutingValue::path("/caf\xE9")->getMessage();

    expect(mb_check_encoding($message, 'UTF-8'))->toBeTrue()
        ->and($message)->toEndWith('got "/caf?".');
});
