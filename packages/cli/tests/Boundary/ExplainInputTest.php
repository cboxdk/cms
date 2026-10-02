<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\ExplainInput;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Throwable;

/*
 * cms:explain's reading of its URL and locale: the host keeps the URL's port, and an empty locale
 * is refused with the hint to give one before it is read as a locale.
 */

it('keeps the port of the URL in the host it resolves', function (): void {
    $query = ExplainInput::read('https://example.dk:8443/nyheder', 'da')->query;

    expect($query)->toBeInstanceOf(ResolvePath::class)
        ->and($query instanceof ResolvePath ? [$query->host->value, $query->path->value, $query->locale->value] : null)->toBe(['example.dk:8443', '/nyheder', 'da']);
});

it('resolves the host alone when the URL has no port', function (): void {
    $query = ExplainInput::read('http://example.dk', 'da')->query;

    expect($query instanceof ResolvePath ? [$query->host->value, $query->path->value] : null)->toBe(['example.dk', '/']);
});

it('refuses an empty locale with the hint to give one', function (): void {
    $refused = null;

    try {
        ExplainInput::read('https://example.dk/', '');
    } catch (Throwable $thrown) {
        $refused = $thrown;
    }

    expect($refused)->toBeInstanceOf(CliCallRefused::class)
        ->and($refused?->getMessage())->toBe('Give the locale to resolve the URL in with --locale, such as --locale=da.')
        ->and($refused?->getPrevious())->toBeNull();
});
