---
title: Inspecting the installation
weight: 29
description: List every action with cms:actions, print the hooks that run for a command in their order with cms:hooks, see why a page looks as it does with cms:explain, and list the panel's extension points and their contributions with cms:panel:points and cms:panel:fills.
---

# Inspecting the installation

These commands answer the questions a developer asks first when something does not behave as expected. Each reads what the kernel itself runs, so what they print is what happens: the actions, hooks and panel points come from the registry `cms:build` compiled to `bootstrap/cache/cms/`, and the explanation of a page is the one `path.resolve` returns when it resolves the page. Each takes `--json` and prints one JSON document instead of lines.

In the development environment, run them in the php container, such as `docker compose exec php vendor/bin/testbench cms:actions`, after `composer dev:prepare`.

## cms:actions

`cms:actions` lists every action of the registry, sorted by the name and then the version of the command or query it handles. For each it prints:

- the command or query it handles, by name, version and class, and whether it writes or reads;
- the surfaces it is exposed on (REST, Inertia, MCP, CLI), or none for an action only the kernel calls;
- the grant it needs: a grant of a role whose permissions (`role_permissions`) hold the name of the command or query;
- the hooks that run for it, in the order they run, with their phase, priority, budget, package and, for an addon's hook, the addon and the highest classification it reads. A query runs no hooks.

With `--json`, the document is `{"actions": [...], "version": 1}`, with keys sorted. Each action has `class`, `package`, `kind` (`write` or `query`), `command`, `command_version`, `command_class`, `surfaces`, `permission` and `hooks`; a hook has `class`, `package`, `phase`, `priority`, `budget_ms`, `addon` and `reads`, the last two null for a hook of a package without a manifest.

## cms:hooks

`cms:hooks <name>`, such as `cms:hooks entry.create`, prints the hook map of a command (PRD 13.2): for each registered version of the command, the hooks that run for it in the order the command pipeline runs them. That is phase in pipeline order (authorize, transform, validate), then priority with the lowest first, then package, then class (PRD 6.3). The pipeline and `cms:hooks` read the hooks from the same place in the registry, so the order is the same. Each line gives the hook's budget, and the heading the budget of all the hooks together, the longest they may take for one call.

With `--json`, the document is `{"command": "<name>", "version": 1, "versions": [{"version": <n>, "budget_ms": <sum>, "hooks": [...]}]}`, with the hooks as `cms:actions` writes them.

A name that no registered command has exits 64 and names the registered commands. So does the name of a query, which has no hooks.

## cms:explain

`cms:explain <url> --locale=<locale>`, such as `cms:explain https://south.example/national/harbour --locale=da`, explains why the page at a URL looks as it does to the public. It reads `path.resolve` for the URL's host and path in the locale, through the query pipeline as the anonymous principal, the same read the delivery surface makes. It prints the typed explanation the read returns, one line per step the resolution reached:

- **site**: the configured site that serves the host (`cbox-cms.sites`), and whether it publishes in the locale;
- **route**: the longest route of the site that is a prefix of the path, every prefix it looked up, and the rest of the path;
- **node** and **mount**: the node the route reaches, and for a mount the source node whose placements it shows;
- **placement**: the slug looked up below the node, and the placement, entry and type found, whether the type has URLs and whether the placement is canonical;
- **visibility**: the rung of the precedence (PRD 6.6) that decided, at what time, from what (the stored state and the window), and until when the decision holds;
- **canonical**: the canonical URL, built from the canonical placement's site origin, and whether it is this URL.

It then prints the content keys the answer is tagged with (`e-<entry>` and `n-<node>`, PRD 9.4) and the read's position.

The query and the fragment of the URL take no part in a resolution and are left out, and the path is percent-decoded. A path with a trailing slash, an empty or a dot segment is not a path `path.resolve` takes; normalising a URL is the delivery surface's work.

An answered read exits 0, whether the page resolves or not, because the explanation is the answer. With `--json`, it prints one line of [`explained-path.v1.json`](../addons/delivery-json.md): `{"content_keys": [...], "explanation": {...}, "read_position": "<xmin>"}`, written by the generated `ExplainedPathCodecV1`. The explanation is a document of `path-explanation.v1.json`, written by the generated `PathExplanationCodecV1`, the one encoding of the explanation, which every surface that shows it uses, the explanation of `GET /v1/resolve` included, so no surface has explain code of its own (GUARDRAILS 2.2, 5): a step the resolution did not reach is null, every other key is always present, and times are UTC with microseconds.

A read the pipeline rejects exits with the error catalog's exit code of its first error, such as 77 for `unauthorized`, and prints the problem details with `--json`.

## cms:panel:points

`cms:panel:points [selector]` lists the panel's extension points (PRD 13.4), each declared with [`#[PanelPoint]`](../addons/panel-points.md), sorted by name and then version. Without a selector it lists every point. A selector with a version, such as `account.me.sections@1`, selects that point; one without, such as `account.me`, selects the versions of the point with that name and every point the page with that name renders. For each point it prints:

- its id, kind, region, how many contributions the panel renders, and for a replacement whose keys an addon may replace and what a key is, and for a decorator the props it may tighten;
- the page that renders it, its stability, the panel API release it arrived in and the number of contributions to it;
- its props class with its package, and the translation key of its label.

With `--json`, the document is `{"points": [...], "version": 1}`, with keys sorted. Each point has `id`, `kind`, `page`, `region`, `multiplicity`, `max`, `ownership`, `keyed_by`, `tightens`, `stability`, `since`, `label`, `class`, `package` and `fills`, the number of contributions; a key that does not apply to the point's kind is null, or an empty list for `tightens`.

## cms:panel:fills

`cms:panel:fills <point>`, such as `cms:panel:fills account.me.sections@1`, lists the contributions to a point in the order the panel renders them: priority with the lowest first, then the addon's namespace, then the contribution's id. Each contribution takes two lines. The first gives its id, its kind, its package and addon, the key a replacement replaces, the command an action runs or a check or step is for, the data query, and the scope it is narrowed to: pages, command forms, types, field types and the permission a viewer must hold. The second gives its priority and whether that is the addon's or the installation's (`cbox-cms.panel.contributions`), and whether the panel renders it: enabled, enabled or disabled by the installation, chosen or passed over by the installation for a replacement's key (`cbox-cms.panel.replacements`), or disabled by the activation state (`cbox-cms.panel.disabled`), which the command reads as it is now. Contributions come from the addon manifests ([panel contributions](../addons/panel-contributions.md)).

With `--json`, the document is `{"fills": [...], "point": "<id>", "version": 1}`, each contribution with `contribution`, `addon`, `package`, `kind`, `priority`, `ordering` (`addon` or `installation`), `enabled`, `enabling` (`addon`, `installation` or `activation`), `key`, `command`, `query` and `scope`, which has `pages`, `commands`, `types`, `field_types` and `requires`.

A selector or id that names no point of the registry exits 64 and names the registered points, and an activation state that is not of its form exits 78.

## Exit codes

| Code | When |
|---|---|
| 0 | the command printed what it was asked for |
| 64 | wrong arguments: a name that is not a registered command's, a URL or locale `path.resolve` does not take, a panel point or page the registry does not hold |
| 78 | the registry cache is missing or damaged (`registry_cache_missing`, `registry_cache_malformed`); run `cms:build` |

A read that `cms:explain` makes and the pipeline rejects exits with the catalog's code for its error, as listed in the [error reference](../reference/errors.md).
