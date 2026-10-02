---
title: Local accounts
weight: 52
description: How a member of staff gets a local account with cms:staff:create, the password policy, how passwords are hashed with Argon2id and rehashed, and how the local connection verifies a login without telling which emails have an account.
---

# Local accounts

A local account is a login and a password kept by the installation itself (PRD 5.16, "Lokale konti"), next to the federated connections such as OpenID Connect. The identity module, `Cbox\Cms\Identity`, owns them. The accounts live in the [credential store](credential-store.md), which only the identity role reaches; the store's contract is [LocalCredentialStore](../addons/contracts/local-credential-store.md).

## Creating a member of staff

`php artisan cms:staff:create --email=<address> --name=<display name>` registers a local member of staff and prints the actor's id, alone on its line. Run it in the maintenance process after `cms:install`, because it runs as the installation operator (see [Maintenance commands](../developers/maintenance-commands.md)).

The password is never an argument or an option, so it never reaches the shell's history or the list of processes. In a terminal the command asks for it twice, hidden. In a script, pipe it to standard input with `--password-stdin`; a line break at its end is not part of it.

The registration runs in a fixed order:

1. Before anything is written, it refuses an email address whose login, the address in lower case, has a local account, and a password the policy below refuses.
2. It hashes the password.
3. `actor.register` makes the actor, of class staff, pending, with the display name and the email address in its profile.
4. The credential is bound to the actor's id in the credential store.
5. `actor.activate` makes the actor active.

Both commands run through the maintenance pipeline as the operator, with the unit of work `staff:<actor id>`, so they are two changesets with their audit, `actor.register` then `actor.activate`. A failure after step 3 leaves the actor pending and no active login: a pending actor never logs in, and the kernel's job deprovisions actors that stay pending for 24 hours.

A member of staff has no grant yet; access is given with roles and grants.

The exit codes come from the error catalog:

| Exit | Code | When |
|---|---|---|
| 0 | | the member of staff is registered and active |
| 64 | | `--email` or `--name` is missing or invalid, no password was given, or the two typed passwords differ |
| 65 | [`password_too_short`](../reference/errors.md#password_too_short), [`password_too_long`](../reference/errors.md#password_too_long), [`password_breached`](../reference/errors.md#password_breached) | the password policy refused the password |
| 65 | [`local_account_exists`](../reference/errors.md#local_account_exists) | the email's login has a local account |
| 75 | [`breached_passwords_unavailable`](../reference/errors.md#breached_passwords_unavailable) | whether the password is breached could not be checked; try again |
| 78 | [`installation_operator_missing`](../reference/errors.md#installation_operator_missing) | `cms:install` has not run |

A command the pipeline rejects exits with the exit code of its first error. No answer, log entry, span or exception message holds the email address or the password.

## The password policy

Every password a local account gets, at registration, at a change and at a reset, must keep three rules, checked in this order:

- at most 1024 bytes in UTF-8, so a long password cannot make hashing slow the server down ([`password_too_long`](../reference/errors.md#password_too_long));
- at least 12 characters, counted as Unicode code points ([`password_too_short`](../reference/errors.md#password_too_short));
- not known from data breaches, as [BreachedPasswords](../addons/contracts/breached-passwords.md) says ([`password_breached`](../reference/errors.md#password_breached)). When the check cannot be made, the password is neither set nor refused.

There is no rule on the kinds of characters: length is what makes a password hard to guess.

## Hashing

Passwords are hashed with Argon2id at the parameters of `cbox-cms.identity.passwords.argon2id`: 64 MiB of memory and 4 passes by default, PHP's own defaults, with one thread (see [Configuration](../developers/configuration.md#identity)). The parameters are part of each hash. When a login verifies a password whose hash was made with other parameters, it hashes the password again with the installation's and replaces the hash, only while the account still has the hash it verified. When the password was set does not change.

## The local connection

The local connection is the [LoginConnection](../addons/contracts/login-connection.md) of the local accounts: connection id `local`, flow `Direct`. The login page takes the email address and the password with the state of the pending login.

It checks that the response belongs to the pending login, then finds the account by the email in lower case, without white space at either end, and verifies the password against the account's hash once. An email without an account, an identifier that is not one and an empty password are verified once against a fixed dummy hash at the installation's parameters, which no password verifies against. They do the same hashing work as a wrong password, so the time of a refusal does not tell which emails have an account. A password longer than 1024 bytes is refused before any hashing. Every refusal is [`login_rejected`](../reference/errors.md#login_rejected), and its message holds neither the email nor the password.

An accepted login gives a verified assertion whose issuer is the installation's local issuer, `cbox-cms.identity.local.issuer` or the application's URL, whose subject is the actor's id, whose `auth_time` is the Clock's time and whose `amr` is `pwd`. Whether the actor may log in, its state, its class and the [login policy](login-policy.md), is decided after the connection, by the login path.
