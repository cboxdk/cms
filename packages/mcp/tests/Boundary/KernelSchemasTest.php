<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Boundary;

use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Tests\Support\Arch\Codebase;

/*
 * The kernel's envelope schema, read from the contracts module of the same package, byte for byte.
 */

it('reads envelope.v1.json of the contracts module', function (): void {
    $path = Codebase::root().'/packages/'.KernelSchemas::DIRECTORY.'/'.KernelSchemas::ENVELOPE;

    expect(KernelSchemas::envelope()->json)->toBe(file_get_contents($path));
});
