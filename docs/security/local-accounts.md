---
title: Local accounts
weight: 52
description: How a member of staff gets a local account with cms:staff:create, the password policy, how passwords are hashed with Argon2id and rehashed, how the local connection verifies a login without telling which emails have an account, and how a password is reset by mail or by an operator.
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

## Resetting a password

A member of staff who forgot the password asks for a link on the panel's page at `/cms/forgot-password`, which the login page links to. The page answers every email the same way, so it never tells which emails have an account:

1. An email left empty is refused with [`validation_required`](../reference/errors.md#validation_required) under the field.
2. The request is counted by a rate limit of its own in Valkey, per email and per IP address: 3 and 20 requests within an hour by default (`cbox-cms.identity.password_reset.throttle`). A request above a limit sends nothing, so the page cannot be used to fill someone's mailbox. A request without a valid client address cannot be counted and sends nothing either, and an email that cannot be a login is not counted, because it names no account.
3. Only a known local account whose actor the [login policy](login-policy.md) would let log in with the method `password_reset` gets a link: the actor is active, the policy of its class allows `password_reset` and has local login on, and the actor is linked to no authoritative connection (invariant 38). The credential store keeps the SHA-256 of a new random token, never the token, with its expiry, 60 minutes by default (`cbox-cms.identity.password_reset.token_minutes`). The link is the reset page's address with the token, `cbox-cms.identity.password_reset.url`, which is `app.url` followed by `/cms/reset-password` when it is not set. It is never built from the request's host, so a forged `Host` header cannot send a link elsewhere.
4. The link is mailed to the account's email through the [mail gateway](egress.md#mail). A mail the transport does not take is counted as a failure; the person sees the same answer, and the token expires unused.

The time of the answer is not made equal: an email that gets a link waits for the mail transport, so the answer can take longer than for one that does not. The rate limit bounds how many emails can be tried this way; an installation that needs the times equal can put a queueing mail transport, such as a local relay, in `mail.default`.

The reset page, `/cms/reset-password/<token>`, sends `Referrer-Policy: no-referrer` and `Cache-Control: no-store`, because its address holds the token. Its form takes a new password, and then:

1. A text that is not in the form of a token, or whose checksum does not match, is refused with [`password_reset_token_invalid`](../reference/errors.md#password_reset_token_invalid) before any lookup, and so is a token the store does not hold unused and unexpired, which it looks up without taking. So is the token of an actor the login policy would no longer let log in with `password_reset`, as in step 3 of the request, for example one deactivated or linked to an authoritative connection since the link was mailed; nothing changes. A dead link therefore costs no breach check and no hashing.
2. The password must keep the policy above. A refused password, and a breach check that cannot be made, leave the token usable.
3. The login policy is asked the same again, and then the store takes the token once, before it expires, and sets the new hash in the same transaction, and takes every other unused token of the account too. A token that is unknown, used or expired is one refusal, [`password_reset_token_invalid`](../reference/errors.md#password_reset_token_invalid), so the answer does not tell which.
4. Every session of the actor is ended, through the session store's set of the actor's sessions, and so is the session the browser still carried.
5. The [login policy](login-policy.md) decides the login with the method `password_reset`. When it allows it, the person gets a new session with a new id and lands on the panel's start page. When it does not, which after steps 1 and 3 means the factors, for example when local staff logins need a passkey, the password stays set and the person signs in on the login page.

Every request adds 1 to the counter `cms.password_reset.requests` with `cms.outcome` (`mailed`, `no_account`, `rate_limited`, `mail_failed` or `no_address`), and every reset to `cms.password_reset.resets` with `cms.outcome` (`logged_in`, `changed` or `refused`) and, for a refusal, `cms.error.code`. No counter, log entry or message holds an email address, a token or a link.

### A link from an operator

When a mail cannot reach the person, an operator prints a link in the maintenance process with `php artisan cms:staff:reset-link <email>` and hands it over another way. It issues the link as the page does and sends nothing. It prints the link alone on the first line and when it expires on the second:

| Exit | Code | When |
|---|---|---|
| 0 | | the link was issued |
| 64 | | the argument is not a login |
| 67 | [`local_account_missing`](../reference/errors.md#local_account_missing) | no local account has the email as its login |
| 77 | [`actor_not_active`](../reference/errors.md#actor_not_active) | the account's actor is not active |
| 78 | [`maintenance_process_required`](../reference/errors.md#maintenance_process_required) | the process is not the maintenance process |

### Pruning the tokens

`php artisan cms:identity:prune` removes the reset tokens that were used, or expired, more than 24 hours ago, and prints how many. The maintenance process's scheduler runs it every hour; it runs only there, and exits 78 with [`maintenance_process_required`](../reference/errors.md#maintenance_process_required) anywhere else.
