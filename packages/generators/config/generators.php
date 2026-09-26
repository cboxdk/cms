<?php

declare(strict_types=1);

/*
 * cms:generate (PRD 11.12), merged into `cms.generators`.
 *
 * Milestone 0 reads one fixture schema in the provisional format `m0-provisional` and writes a PHP
 * enum of the type handles and a TypeScript union of them. The paths follow the application
 * layout in PRD 11.12 and are relative to `root`. Milestone 1's blueprint schema v1 replaces the
 * schema setting.
 *
 * cms:generate owns php_directory and typescript_directory: it removes every file there that it
 * did not generate. Both must therefore end in a directory named Generated or generated.
 */

return [
    // The directory the other paths are relative to. Null is the application's base path.
    'root' => null,

    // The schema file.
    'schema' => 'schema/fixture.yaml',

    // Where the PHP code goes, and its namespace.
    'php_directory' => 'app/Cms/Generated',
    'php_namespace' => 'App\\Cms\\Generated',

    // Where the TypeScript goes.
    'typescript_directory' => 'resources/js/cms/generated',
];
