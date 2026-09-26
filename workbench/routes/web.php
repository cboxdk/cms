<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The workbench's web routes, loaded by Testbench with the web middleware group because
 * testbench.yaml discovers them. The start page is plain HTML with no script, so a browser test
 * can assert its text and that it logs nothing and throws nothing (GUARDRAILS 9).
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
