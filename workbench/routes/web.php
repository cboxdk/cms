<?php

declare(strict_types=1);

use Cbox\Cms\Http\Inertia\Adapter\InertiaMiddleware;
use Cbox\Cms\Http\Inertia\InertiaRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/*
 * The workbench's web routes, loaded by Testbench with the web middleware group because
 * testbench.yaml discovers them. The start page is plain HTML with no script, so a browser test
 * can assert its text and that it logs nothing and throws nothing (GUARDRAILS 9).
 *
 * Below it, the Inertia profile's test page and route (GUARDRAILS 2.1), until the panel comes with
 * B1: the page Workbench/Commands at /workbench/inertia, rendered by the root view `app`, and the
 * profile's command route at /workbench/inertia/commands/{command}/v{version}, both behind the
 * profile's middleware, so a rejected call's errors and problem reach the page as props.
 */

Route::get('/', static fn (): string => <<<'HTML'
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Cbox CMS workbench</title>
        <link rel="icon" href="data:,">
    </head>
    <body>
        <h1>Cbox CMS workbench</h1>
    </body>
    </html>
    HTML);

Route::middleware(InertiaMiddleware::class)->prefix('workbench/inertia')->group(static function (Registrar $router): void {
    $router->get('/', static fn (): Response => Inertia::render('Workbench/Commands'))->name('workbench.inertia');

    InertiaRoutes::register($router);
});
