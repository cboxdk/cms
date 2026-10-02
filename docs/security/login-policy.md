---
title: Login policy
weight: 52
description: The login policy per actor class and environment, which every login path asks before a session is issued, its defaults, and the rules it decides in order.
---

# Login policy

Every way to log in asks the login policy before a session is issued (PRD 5.16, "Loginpolitik"): a password, a passkey, a magic link, a social login, accepting an invitation, resetting a password, and a login through a federated connection. The policy is configuration in git per environment, under `cbox-cms.identity.policy` (see [Configuration](../developers/configuration.md#identity)), with one part for members of staff and one for end users. A service actor never logs in; it only holds service credentials.

## A session needs a decision

A session is issued only with a `LoginDecision`, and only the policy check makes one: its constructor is private, so PHPStan refuses a login path that makes its own decision instead of asking the policy. A login path calls the identity module's `CheckLoginPolicy` with the actor it found, the method and the assertion the connection verified, and gets the decision or a refusal. The decision carries what the session stores: the actor, its class and its credential generation, the connection, the method, when the person authenticated, and the session's lifetimes.

The check reads the actor's class, state and credential generation through the actor directory, from Postgres, and refuses with the first rule that fails, in this order:

1. The actor exists, or the login is refused with [`actor_not_active`](../reference/errors.md#actor_not_active).
2. Its class logs in: a service actor never does ([`login_class_not_allowed`](../reference/errors.md#login_class_not_allowed)).
3. It is active, not pending, deactivated or deprovisioned ([`actor_not_active`](../reference/errors.md#actor_not_active)).
4. The policy of its class lists the connection ([`login_connection_not_allowed`](../reference/errors.md#login_connection_not_allowed)).
5. The policy lists the method, and the method belongs to the connection: every method but `federated` to the local connection, `federated` to every other ([`login_method_not_allowed`](../reference/errors.md#login_method_not_allowed)).
6. For the local connection, local login is switched on ([`login_local_disabled`](../reference/errors.md#login_local_disabled)), and the actor is not linked to a connection marked authoritative, whose identity provider owns the actor's access (invariant 38, [`login_authoritative_link`](../reference/errors.md#login_authoritative_link)). The links are read from `cms_identity.idp_links` (see [Credential store](credential-store.md)).
7. The login gave the factors the policy requires ([`login_factors_unavailable`](../reference/errors.md#login_factors_unavailable)).

The person is only told that the login failed; the reason goes to the log. Every decision adds 1 to the counter `cms.login.decisions` with `cms.outcome`, `allowed` or `refused`, and for a refusal `cms.error.code`, the code of the rule. No actor id, login identifier or e-mail address reaches the counter.

## Factors

A local login needs the factors of `local_factors`: `password`, where one factor is enough, or `passkey_or_two_factors`, where the login is a passkey or the connection's amr claim names `mfa` or two methods. PRD 5.16 requires a passkey or two factors for a local staff login, and that is the default. B1 part 1 offers neither a passkey nor a second factor, so an environment that keeps the default refuses every local staff login with `login_factors_unavailable`; the workbench, a development environment, sets `password`. Passkeys, one-time codes and step-up come with B1 part 2.

A federated login needs MFA only when the policy names it: one of the values of `federated_amr` in the amr claim, or one of `federated_acr` as the acr claim. Set them once the identity provider is shown to send them.

## Defaults

| | Staff | End users |
|---|---|---|
| Connections | `local` | `local` |
| Local login | on | on |
| Local factors | a passkey or two factors | a password |
| Methods | password, passkey, invitation, password reset, federated | all |
| Inactivity | 60 minutes | 30 days |
| Absolute lifetime of a session | 12 hours | 90 days |

PRD 5.16 proposes that local login for staff is switched off once a federated connection and two emergency accounts exist. Neither exists before B1 part 2, so local login stays on; switch it off with `local_login` once they do.

## Not yet

Registering the policy at deploy with the event `login_policy.registered` to the SIEM, refusing a deploy that loosens it without a code owner's approval, and refusing to switch local login off for staff with fewer than two emergency accounts come with the audit trail to the SIEM in B6. The authentication log, `auth_log`, and the work context come in B1 part 2, as do step-up and one-time codes.
