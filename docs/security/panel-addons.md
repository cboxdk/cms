---
title: Panel addons
weight: 53
description: "What an installed addon's UI in the panel can and cannot do: it runs as trusted code in the panel's window, what the server, the build and the Content-Security-Policy enforce, and what they do not."
---

# Panel addons

An addon can add UI to the control panel at its declared points ([panel UI](../addons/panel/_index.md)). This page says plainly what that UI can do once it is installed, what the kernel enforces against it, and what it does not.

## Installed addon UI is trusted code

An addon's UI runs in the panel's window, as a script of the panel's origin, with the session of the person viewing the page. The browser has no mechanism that isolates it from the rest of the page, so the panel treats an installed addon's UI as trusted code:

- It can read what the viewer sees. The root view writes the first page's props into the document, and Inertia keeps each page's props in the browser's history state, where any script on the page can read them. The addon's `reads` capability limits what the panel hands a contribution, not what a hostile addon can read on the page.
- It can act as the viewer. A request it sends from the page carries the viewer's session and can carry the CSRF token, so it can run any command the viewer may run. `issues` limits what the host's `runCommand()` lets a contribution run, not a script that posts by hand.
- It can change the page. A script can change any element of the document, the core's own included. The style scoping and the monotone rules of the points hold an addon that keeps to the SDK, not one that does not.
- Its provenance, `addon:<namespace>:<contribution>`, is what the browser code says it is. The changeset records it, and it is no proof.

So install addon UI only from a publisher you trust, review the bundle before you trust the publisher's key, and keep the allowlist (`cbox-cms.addons.allowed`) to what you reviewed.

## What is enforced, and where

| Concern | Enforced by | Holds against |
|---|---|---|
| What the viewer may do | the command and query pipelines on the server: grants, the escalation guard, hooks and validation | everything; an addon has no principal of its own in the panel, and every command and read runs as the viewer |
| What a contribution is handed | the point's props and the data query's result, each written at the lower of the viewer's classification access and the addon's `reads` | data reaching an addon through its contract; not a hostile addon reading the page |
| Which points an addon touches | `cms:build`: the manifest's contributions, kinds, ownership, conflicts, mirrored checks and declared paths | accidental or undeclared reach |
| Which commands the UI offers | `cms:build` holds actions and steps to `issues`; the host refuses any other command | accidental use; not a script that posts by hand |
| Which files run | `cms:build` checks every file's SHA-384 and the publisher's Ed25519 signature; the panel serves only compiled files, by hash, and refuses a file that changed; the import map carries each script's integrity | tampered or foreign files |
| Where code comes from | the Content-Security-Policy: `script-src 'self'` with the hash of the import map, no nonce for scripts and no `'strict-dynamic'`, so `import()` stays on the panel's origin | scripts and modules from another origin |
| Where data can go | the Content-Security-Policy: `connect-src`, `img-src`, `form-action` and `font-src` on the panel's origin, `frame-src`, `object-src` and `base-uri` none | requests, form posts, images and frames to another origin; not a top-level navigation, which no directive covers |
| Credential pages | the login, password reset and logout routes and the page for an unknown address carry no addon code, which `CredentialRoutesWithoutAddonsTest` holds | addon code near a password or a reset link |
| Which modules an addon imports | the import map's scope for each addon refuses Inertia, React Aria and the kit's own package, and the SDK's build plugin and lint refuse them at build | accidental reach into the panel's internals; a review aid, not a control |

The policy and the shared modules are described on [the panel module](../developers/panel.md#the-content-security-policy). A violation the browser reports is counted per directive and per addon in telemetry; the report itself is not kept.

## Signatures

`cms:build` refuses an addon's bundle unless its signature verifies with a key the installation trusts for the addon in `cbox-cms.addons.publishers`. The signature covers `panel-manifest.json`, which holds the SHA-384 of every file. Without a trusted key for an addon, a bundle passes only in the local environment, so an addon's UI can be developed before its key is known. Trusting a key means reviewing where it came from: the key is what makes the bundle the publisher's ([signing the bundle](../addons/panel/contributions.md#signing-the-bundle)).

## When something goes wrong

- `cbox-cms.panel.disabled` turns off a contribution, or an addon's whole panel UI, at the next request, without a rebuild. `cms:panel:fills` shows what the activation state turned off.
- A contribution that throws shows a notice that names its addon, and the rest of the page renders. The host reports the failure once per session as a code with the addon, point and contribution ids, never the message, as the window event `cms:panel-report`; a data query that fails on the server is recorded in telemetry per addon.
- `cms:doctor`'s `panel.addons` reports a bundle whose files no longer match what `cms:build` compiled, and `panel.dev_server` a dev server for addon UI outside the local environment.

## What is not there

- **An install screen.** `DiscloseAddons` gives what an install screen shows of each addon from the compiled registry: the points it touches and which are experimental, `issues`, `reads`, `uiTheme`, its bundle and the statement that its UI runs in the panel's window with the viewer's session. No page of the panel shows it.
- **Isolation of addon UI.** There is no sandboxed frame for addon UI. An addon from an unreviewed source, such as a marketplace, cannot be run safely in the panel.
- **Personal data kept out of page props.** The who-am-I page carries the viewer's own name and email in its props, and the grants page the names and emails of other staff when the viewer's classification access allows personal data. Every addon on those pages can read them.
- **A rule against navigation.** No directive of the Content-Security-Policy stops a script from sending the browser to another origin with data in the address.
