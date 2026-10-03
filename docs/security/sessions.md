---
title: Sessions
weight: 52
description: The session of a person who logged in, a credential like any other, how it is issued, renewed and ended, and the cookie that carries it.
---

# Sessions

A person who logs in gets a session, and the session is a credential (PRD 5.16, "Sessioner og credentials"). Every command and every read made with it goes through the same verifier as a service credential, and runs as an actor of the issuer kind `human`.

## Issuing a session

The identity module's `IssueSession` takes only a `LoginDecision`, which only the [login policy](login-policy.md) makes, so no login path issues a session the policy did not allow. Every session gets a new id, never one the request carried, so an id planted before the login is never the one that logs in.

The id is `SessionToken`: the prefix `cms_ss_`, 256 random bits as 64 hex digits, and a CRC-32 checksum as 8 hex digits. A verifier refuses an id whose checksum does not match before it looks anything up ([`credential_malformed`](../reference/errors.md#credential_malformed)). The store keeps a session only under the SHA-256 of its id, with the actor, its class and credential generation, the connection, the login method, the identity provider's session id (`sid`) or none, and when it was issued and last seen.

## The store

Sessions live in Valkey, on the default Redis connection, which must be phpredis to one Valkey primary. Each session is a hash under `cms:session:<SHA-256 of the id>`. Two kinds of set name the sessions: one per actor, `cms:sessions_of_actor:<actor>`, and one per connection and IdP session, `cms:sessions_of_idp:<connection>:<SHA-256 of the sid>`. Ending every session of an actor, or every session from one IdP session as a back-channel logout names it, deletes what the set names and never scans the keys. Every change is one Lua script, so a session and the sets that name it change together.

A session is kept 10 minutes past its end, so a request just after it ended is told [`credential_expired`](../reference/errors.md#credential_expired) instead of finding nothing.

## Each request

Each request with a session checks, in this order:

| Check | Refused with |
|---|---|
| the id is in its form and its checksum matches | `credential_malformed` |
| the store has the session | `credential_unknown` |
| it has not ended: its inactivity end, the last request plus `inactivity_minutes`, and its absolute end, the login plus `absolute_minutes`, are both later than now | `credential_expired` |
| the actor is active, read from Postgres | `actor_not_active` |
| the actor's credential generation is not above the session's | `credential_revoked` |
| the login policy still allows the session's connection and login method; for a session from the local connection, local login is still on, the factors the login gave still meet the class's `local_factors`, and the actor is linked to no authoritative connection | `credential_not_allowed` |

The lifetimes are those of the login policy now, so a shorter lifetime applies to the sessions already issued. A request that passes renews the session: its inactivity end slides to the request plus `inactivity_minutes`, but never past the absolute end. A refused session is ended at once.

The actor's state and generation are read from Postgres at each request. A deactivation or a revocation counts the generation up, so every session of the actor is refused at its next request. For a session from the local connection, the actor's IdP links are read from the identity store at each request too, so a link to an authoritative connection made after the login refuses the session at its next request. A copy of the actor's state in Valkey is not built yet.

## Logging in with a password

The panel's login form logs a member of staff in through the local connection (`LogInLocally`). The form posts the email and the password; the local connection verifies the password exactly once, against the account's hash or a dummy hash, and the login policy decides. An unknown email, a wrong password and an actor that may not log in are all refused with [`login_rejected`](../reference/errors.md#login_rejected), and the page shows one message for all of them, so it never tells whether an account exists. A login that succeeds ends the session the browser still carried, if any, and issues a new one.

Logins are rate limited in Valkey before any password is checked. Every attempt is counted under the SHA-256 of the email, in lower case, and under the SHA-256 of the client's IP address, each for a window from its first attempt: by default 5 attempts per email and 50 per address in 15 minutes, set under `cbox-cms.identity.login.throttle` (see [Configuration](../developers/configuration.md#identity)). The attempt after them is refused with [`login_rate_limited`](../reference/errors.md#login_rate_limited) and adds 1 to the counter `cms.login.rate_limited` with `cms.limit`, `identifier` or `ip`. A login that succeeds clears its email's count and takes itself off its address's. Neither the throttle nor the counter holds an email or an address. The address is read in its canonical text, so two spellings of one IPv6 address count as one; a request without a valid client address, and an email that cannot be a login, such as one with a space inside, are refused with `login_rejected` without being counted or checked.

## The panel and Laravel's session

In the panel the CMS session is the only credential. Laravel's session, with its own cookie, carries only Inertia's flash data, the errors prop, the CSRF token and a binding: the SHA-256 of the CMS session's id, never the id. An application should keep Laravel's session in Valkey too (`SESSION_DRIVER=redis`), as the workbench's `.env.example` does.

- At a login both get new ids: the CMS session is a new one, and Laravel's session is emptied and given a new id, a new CSRF token and the binding to it.
- At every other panel request the CMS session is verified and renewed. A Laravel session bound to another CMS session, or to none, is emptied and given a new id and CSRF token before it is bound, so a Laravel session planted in the browser never brings its CSRF token into a person's session.
- A request without a session, or with one the verifier refuses, goes to the login page with the reason: `required`, `expired`, `ended` or `revoked`. A refused session's cookie is cleared and Laravel's session emptied.
- At a logout both are ended and both cookies are cleared, and the login page says `signed_out`.

Every state-changing panel request must carry the CSRF token of Laravel's session, which the panel's pages send as Inertia does, in the header `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie; any other is refused with 419. The panel checks it itself, whatever the application's own CSRF middleware excludes. The Inertia command profile below the panel, `POST <prefix>/commands/{command}/v{version}`, takes the session as its credential, so a form in the panel runs a command as the person, with the envelope's issuer kind `human`. The REST surface takes only a Bearer credential and never reads a cookie, so it has no CSRF exposure.

## Ending sessions

`EndSessions` ends one session at a logout, every session of an actor, or every session from an IdP session. Ending a session deletes it from the store; it is not a command, so logouts never become changesets.

## Telemetry

Each session issued adds 1 to the counter `cms.session.issued` with `cms.login.method`. Each session ended adds to `cms.session.ended` with `cms.reason`: `logout`, `expired`, `revoked` (the actor is not active, or the generation is too low), `policy`, `actor` (every session of the actor was ended) or `idp_session`. Neither carries a session id, an actor or a connection.

## The cookie

The session id travels in a cookie that is always HttpOnly, with the path `/` and no Domain. It has no expiry of its own, so it lives as long as the browser, while the store holds the session's lifetimes, and Laravel does not encrypt it, because the id is random and only the store can use it. Its name, Secure and SameSite are set per environment under `cbox-cms.identity.session.cookie` (see [Configuration](../developers/configuration.md#identity)); an environment the map does not name takes the entry of `production`.

| Environment | Name | Secure | SameSite |
|---|---|---|---|
| `production` and any other | `__Host-cms_session` | yes | Lax |
| `local` and `testing` | `cms_session` | no | Lax |

Local and testing go without Secure and the `__Host-` prefix, because the workbench and the browser tests serve plain HTTP on 127.0.0.1. In every other environment the cookie must be Secure, named with `__Host-`, which a browser accepts only from HTTPS with path `/` and no Domain, and not SameSite=None. A process that serves HTTP refuses to boot otherwise ([`session_cookie_insecure`](../reference/errors.md#session_cookie_insecure)), as it does with a setting it cannot use ([`session_cookie_invalid`](../reference/errors.md#session_cookie_invalid)). Console processes still boot, and the blocking doctor check `identity.session_cookie` says what is wrong.
