<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-notes. Its scan root is the directory it lies in,
 * so cms:build registers the command, the hook, the query and its action next to it.
 */
final class NotesServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-notes', __DIR__)];
    }
}
