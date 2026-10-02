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

    'local' => [
        // The issuer every login through the local connection names (PRD 5.16): an https URL, or an
        // http URL of a loopback host. Null takes the application's URL, app.url.
        'issuer' => null,
    ],

    'passwords' => [
        // The Argon2id parameters the local accounts hash their passwords with (PRD 5.16): memory
        // in KiB, 1024 to 4194304, and passes, 1 to 64, with one thread. PHP's defaults, 64 MiB and
        // 4 passes, are above the OWASP minimum. A login whose hash was made with other parameters
        // is hashed again with these.
        'argon2id' => [
            'memory_kib' => 65536,
            'time' => 4,
        ],
    ],

    'session' => [
        // The cookie that carries the session id (PRD 5.16, docs/security/sessions.md), per
        // environment. An environment without an entry takes the entry of `production`. The cookie
        // is always HttpOnly, with path / and no Domain. Outside local and testing it must be
        // Secure, named with the __Host- prefix and not SameSite=None: a process that serves HTTP
        // refuses to boot otherwise, and cms:doctor's identity.session_cookie says why. Local and
        // testing go without Secure and the prefix, because the workbench and the browser tests
        // serve plain HTTP on 127.0.0.1.
        'cookie' => [
            'production' => ['name' => '__Host-cms_session', 'secure' => true, 'same_site' => 'lax'],
            'local' => ['name' => 'cms_session', 'secure' => false, 'same_site' => 'lax'],
            'testing' => ['name' => 'cms_session', 'secure' => false, 'same_site' => 'lax'],
        ],
    ],

    // The login policy of this environment (PRD 5.16, docs/security/login-policy.md), per actor
    // class: every login path asks it, and a session is issued only with its decision. A service
    // actor never logs in and has no policy. `connections` and `methods` map a name to whether the
    // class may use it. The defaults are the PRD's proposed ones.
    'policy' => [
        // Federated connections whose identity provider owns the access of the actors linked to
        // them: such an actor has no local login (invariant 38). Never `local`.
        'authoritative_connections' => [],

        'staff' => [
            // `local` is the local accounts of this module; every other name is a federated
            // connection.
            'connections' => ['local' => true],
            'methods' => [
                'password' => true,
                'passkey' => true,
                'magic_link' => false,
                'social' => false,
                'invitation' => true,
                'password_reset' => true,
                'federated' => true,
            ],
            // On until a federated connection and two emergency accounts exist; switch it off then.
            'local_login' => true,
            // A local staff login needs a passkey or two factors (PRD 5.16). Set `password` only in
            // an environment that does not offer them, such as development.
            'local_factors' => 'passkey_or_two_factors',
            // The MFA a federated login must show: one of these amr or acr values. Empty requires
            // none; set them once the identity provider is shown to send them.
            'federated_amr' => [],
            'federated_acr' => [],
            'inactivity_minutes' => 60,
            'absolute_minutes' => 720,
        ],

        'end_user' => [
            'connections' => ['local' => true],
            'methods' => [
                'password' => true,
                'passkey' => true,
                'magic_link' => true,
                'social' => true,
                'invitation' => true,
                'password_reset' => true,
                'federated' => true,
            ],
            'local_login' => true,
            'local_factors' => 'password',
            'federated_amr' => [],
            'federated_acr' => [],
            // 30 days and 90 days.
            'inactivity_minutes' => 43200,
            'absolute_minutes' => 129600,
        ],
    ],
];
