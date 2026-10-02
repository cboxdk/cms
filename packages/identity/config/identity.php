<?php

declare(strict_types=1);

/*
 * The identity module (PRD 5.16), merged into `cbox-cms.identity`.
 *
 * The credential store of the local accounts lives in the schema cms_identity, which the app role
 * cannot read. Only the identity role reaches it, on a database connection of its own; the
 * application never reads a credential through the default connection. Create the role, the
 * schema and the connection as docs/security/credential-store.md says.
 */

return [
    // The database connection of the identity role, in config/database.php: the same server and
    // database as the default connection, with the identity role's username and password.
    'connection' => 'pgsql_identity',
];
