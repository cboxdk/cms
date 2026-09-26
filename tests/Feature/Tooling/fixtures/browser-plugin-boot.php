<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Fixtures;

/*
 * Fixture for BrowserToolchainTest, run in a separate Pest process. It is not a suite file (no
 * Test.php suffix). The browser plugin treats a test whose closure calls visit() as a browser
 * test: before the first one it starts the Playwright server and empties tests/Browser/Screenshots.
 * This test never calls the closure, so no browser is launched and Chromium is not needed.
 */

it('fixture: is a browser test that opens no page', function (): void {
    expect(static fn (): mixed => visit('/'))->toBeCallable();
});
