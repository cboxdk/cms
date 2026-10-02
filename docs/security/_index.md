---
title: Security
weight: 50
description: The operating contract for the Postgres roles and row level security, the rule for outbound requests, and an honest account of what the kernel protects today.
---

# Security

The kernel's security rests on rules that the code, the tests and `cms:doctor` hold, not on configuration an operator has to remember. This section describes those rules, and says plainly where the kernel does not protect anything yet.

- [Postgres roles](postgres-roles.md): the app role and the owner role, row level security, and the processes that may hold the owner's credentials.
- [Credential store](credential-store.md): where the local accounts keep their credentials, the identity role that alone reaches them, and how to set it up in production.
- [Login policy](login-policy.md): the login policy per actor class and environment, which every login path asks before a session is issued.
- [Sessions](sessions.md): the session of a person who logged in, how long it lives, how it ends, and its cookie.
- [Egress](egress.md): the rule for outbound requests, and `allow_url_fopen`.
- [Scope](scope.md): what the kernel protects today, and what it does not.
