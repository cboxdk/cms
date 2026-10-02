---
title: Scope
weight: 54
description: What the kernel protects today, what it does not protect yet, and what is left to the operator.
---

# Scope

Cbox CMS is in development, and this page says what its security covers today. It is kept to what the code does.

## What the kernel protects today

- **The schema from the application.** The web and queue processes connect as a role that owns nothing and cannot change the schema, and `cms:doctor` refuses to let the kernel start when that role has more power. See [Postgres roles](postgres-roles.md).
- **The owner's credentials.** A process that serves HTTP or runs queued jobs does not boot when it has the owner connection.
- **Outbound requests.** Every outbound request goes through the egress gateway, which refuses private, reserved and cloud metadata addresses, pins DNS, never follows a redirect and has timeouts. The architecture tests keep every other outbound request, and every program run, out of the kernel's code outside a short list of reasoned exceptions, and `allow_url_fopen` must be off. See [Egress](egress.md).
- **Credentials from the application.** The credentials of the local accounts live in the schema `cms_identity`, which only the identity role reaches; the app role has no privilege on it, and `cms:doctor` refuses to let the kernel start when it does. See [Credential store](credential-store.md).
- **Stored receipts and idempotency records.** The app role can read and add them, but not change or delete them; a projection's status is the only thing it may update.
- **Ids are not secrets.** Ids are UUIDv7 and tell the time they were made. The documentation of the [id generator](../addons/contracts/id-generator.md#ids-are-not-secrets) says so, and no id is used as a token.
- **Internal API.** An addon cannot use `#[Internal]` API without PHPStan reporting it.

## What the kernel does not protect yet

- **There is no authentication, no authorisation and no row level security policy.** The kernel has no HTTP routes and no command pipeline yet. They come with the next milestones, and the rules above are in place for them.
- **There is no audit log, no encryption of fields and no handling of personal data.** The blueprint schema v1 refuses the classifications `personal` and `sensitive` until the keys such fields require arrive; see [Personal data](../addons/blueprint-v1.md#personal-data).
- **The SSRF guard is not a network rule.** It checks each URL in the application; a network rule that lets the servers reach only what they need is still the operator's, and a proxy set with `HTTP_PROXY` defeats the pin to the checked addresses. See [Egress](egress.md#what-the-gateway-does-not-do).

## What the operator does

- Run Postgres 17 or newer with the two roles, the role settings and `max_prepared_transactions = 0`, and give the owner's credentials only to the maintenance process. Create the identity role, the schema `cms_identity` and the identity connection as [Credential store](credential-store.md) says.
- Run PHP with `allow_url_fopen` off, keep `ssrf.enforce` and `ssrf.pin_dns` of cboxdk/laravel-ssrf on, and set no `HTTP_PROXY` for the PHP processes.
- Run `cms:doctor` in every type of process after each deploy, and act on what it reports.
- Keep Postgres and Valkey off public networks. The development services bind to `127.0.0.1` only, and their credentials are for development.
