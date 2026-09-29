<?php

declare(strict_types=1);

/*
 * cms:generate (PRD 11.12), merged into `cbox-cms.generators`.
 *
 * cms:generate reads the blueprint v1 files below the schema roots and writes a PHP enum of the
 * type handles and a TypeScript union of them, each with the fields of every type. The paths follow
 * the application layout in PRD 11.12 and are relative to `root`.
 *
 * cms:generate owns php_directory, typescript_directory and migrations_directory: it removes every
 * file there that it did not generate. The first two must therefore end in a directory named
 * Generated or generated, and migrations_directory in migrations/cms.
 */

return [
    // The directory the other paths are relative to. Null is the application's base path.
    'root' => null,

    // The schema roots: each owner and the directory of its blueprint files. Every *.yaml file
    // below a directory is a blueprint file, and its owner is the owner of every definition in it.
    // The application's own types and extensions are owner `app`.
    'roots' => [
        'app' => 'schema',
    ],

    // Where the PHP code goes, and its namespace.
    'php_directory' => 'app/Cms/Generated',
    'php_namespace' => 'App\\Cms\\Generated',

    // Where the TypeScript goes.
    'typescript_directory' => 'resources/js/cms/generated',

    // Where the migrations of the type tables go, next to the schema lock of each type table they
    // are computed from (PRD 11.6). The generated service provider registers the directory with
    // the migrator, and the migrations run with `php artisan migrate` like any other.
    'migrations_directory' => 'database/migrations/cms',
];
