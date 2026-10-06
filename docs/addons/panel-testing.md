---
title: Testing and scaffolding addon UI
weight: 53
description: "What an addon's own CI runs on its panel UI: the SDK's test helpers in @cboxdk/cms-panel/testing, cms-panel-addon verify on the committed bundle, the testkit's PanelContributionsContract and PanelVisit, and the scaffolds cms:make:addon-ui and cms:make:panel write."
---

# Testing and scaffolding addon UI

<!-- extension-point: Cbox\Cms\Testkit\Panel\PanelContributionsContract -->

An addon's panel UI is tested in the addon's own repository, before an installation sees it (PRD 13.4, section 7 of the panel extension architecture). Four pieces of `cboxdk/cms` carry that: the SDK's subpath `@cboxdk/cms-panel/testing` for the contributions' own tests, the bin `cms-panel-addon verify` for the committed bundle, the testkit's `PanelContributionsContract` for the manifest and `PanelVisit` for a Pest Browser test of the panel with the addon in it, and the scaffolds `cms:make:addon-ui` and `cms:make:panel`, which write a working start with these tests in place. Everything on this page is `#[Experimental]`, as the whole panel API is at B1.

## The SDK's test helpers

`@cboxdk/cms-panel/testing` renders and checks a contribution the way the panel's host does, with a fake host and in jsdom, so a test needs no browser and no installation. A test file that renders a contribution starts with the comment `// @vitest-environment jsdom`.

| Export | What it does |
|---|---|
| `createFakeHost(options)` | Builds a host that does what the panel's host does for one contribution, only against what the test gives it: `texts` of the addon's own catalogue in `locale` (a key outside the addon's namespace, or missing, shows as the key), `pages` the contribution may navigate to, `issues`, the commands it may issue as `<name>@<version>`, `answer`, what a command answers, and `confirm`, what the viewer answers a dialog. Everything it was asked is in `host.record`: `commands` with the provenance `addon:<namespace>:<contribution>`, `notices`, `visits`, `dialogs` and `refusals`. A command the addon may not issue is refused with `PanelCommandRefused`, as the panel refuses it. `PanelHostProvider` provides any host to the contributions below it. |
| `committedReceipt()`, `dryRunReceipt()`, `rejectedProblem(code, errors)` | The answers a fake host gives a command: a receipt of `receipt.v1.json`, a dry run with its summary, and problem details of `problem.v1.json` with the errors at their paths, each in the form the generated validators accept. |
| `renderPoint({ kind, addon, id, ... })` | Renders one contribution of the addon's registration as the host renders it on a point of the kind: `renderSlot` (with the point's `props`, the `region` and the `data` of its query), `renderPage`, `renderReplacement`, `renderProvider`, `renderDecorator` (composed onto a default, with the tightenings the manifest declares) and `renderStep` (with the controls the host gives a flow step, each recorded). The result holds the `container`, the `host` and `rerender()` and `unmount()`. A contribution that throws, or is not what its kind takes, fails with `ContributionContractBroken` naming what it did. |
| `expectSlotContract`, `expectPageContract`, `expectReplacementContract`, `expectProviderContract`, `expectDecoratorKeepsDefault`, `expectFlowStepContract`, `expectFormCheckContract`, `expectObserverContract` | The conformance suite, one helper per kind: each renders or runs the contribution as the host does and fails on what the host would refuse or isolate. A slot or page with a data query renders in every state of its data, loading, failed and ready; a decorator keeps the default and tightens only what its manifest declares; a flow step patches only its declared paths and waits for the viewer; a form check answers within 16 ms with issues in the addon's namespace no heavier than its declared severity, and answers the same twice. |
| `checkParity(check, hook, documents)` | Holds a mirrored form check to its hook (section 3.7): on each document, the paths the check blocks with an error must be the paths the hook refuses, or `ParityBroken` names each disagreement. The hook runs in PHP, so its verdicts come from the addon's own PHP tests, recorded as the paths refused per document. |
| `expectNoA11yViolations(element)` | Runs axe on what a contribution rendered, with the rules of WCAG 2.2 at levels A and AA that the panel's own pages are held to, and throws `A11yViolations` with every violation. The colour contrast rule is left to the panel's browser gates, because jsdom lays nothing out. |
| `expectRegistration(addon, ids)` | Holds the registration's keys to the ids of the manifest's contributions that run code, as `cms:build` compiled them, because the panel renders none of them when they differ. |

The fake host is held to the panel's real host by a behaviour test in this repository, as every fake of a port is: one set of behaviours runs against both, so what a contribution sees in a test is what it sees in the panel.

## cms-panel-addon verify

The addon's prebuilt bundle, `dist/panel`, is committed in its release commit (decision D7), so the addon's CI runs `cms-panel-addon verify`, the bin of `@cboxdk/cms-panel`, from the package directory: it reads `dist/panel/panel-manifest.json`, checks every file it lists against its SHA-384, so a file edited by hand after the build fails, builds the addon again into a directory of its own with the addon's Vite configuration, and compares the two manifests file by file, so a bundle that is not what the sources give fails too. It exits 0 when the bundle verifies, 1 with each problem on standard error when it does not, and 64 for arguments it does not take (`--root=<dir>` and `--dist=<dir>`).

## The testkit's PanelContributionsContract

`Cbox\Cms\Testkit\Panel\PanelContributionsContract` is the shared suite an addon's PHP tests run on its manifest: use the trait in a PHPUnit test class on the addon's Testbench application, with package discovery on, so the addon's provider is registered, and a bootstrap directory of the test's own, so `cms:build` writes its cache there, and give the manifest the provider declares. Its case allows the addon's package in `cbox-cms.addons.allowed`, runs `cms:build` on the whole installation and fails with the build's output on any problem, an unknown point, a kind mismatch, an experimental point the manifest does not accept, a bundle whose files or ids are not the manifest's, a blocking check without a mirrored hook and every other refusal of [panel contributions](panel-contributions.md#what-cmsbuild-checks). It then reads what the build wrote and asserts that the addon was compiled and that every contribution of the manifest is on its point.

The approvals addon of the [panel contributions](panel-contributions.md#example) example runs it in its own tests:

<!-- example: examples/Unit/Panel/PanelContributionsContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Testkit\Panel\PanelContributionsContract;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Approvals\ApprovalsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Override;

/**
 * The approvals addon runs the testkit's shared suite in its own tests: the installation with
 * the review package's points and the addon's manifest builds, and the addon's badge is compiled
 * onto the section it fills. The suite's case fails with the build's output on any refusal.
 */
final class PanelContributionsContractTest extends BuildTestCase
{
    use PanelContributionsContract;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app()->register(ReviewsServiceProvider::class);
        app()->register(ApprovalsServiceProvider::class);
    }

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return new ApprovalsServiceProvider(app())->addonManifest();
    }
}
```

## PanelVisit for a Pest Browser test

`Cbox\Cms\Testkit\Panel\PanelVisit::as($actor, $path)` is `visitPanelAs` for an addon's Pest Browser tests, with `pestphp/pest-plugin-browser` in the addon's `require-dev`: it gives the actor a local account with a password of the test's own through the `LocalCredentialStore`, signs in through the panel's login page as a person does, and lands on the path below the panel's prefix, `/cms` unless the application mounts the panel elsewhere, given as the third argument. The visit's `page` is the browser page, for every assertion the plugin has. `assertFill($id)` and `assertNoFill($id)` assert that the page renders a contribution with the id, or none, and `assertFillOrder($point, [$ids])` that the point renders exactly those contributions in that order; the three read the attributes the panel's host puts on what it renders on a point, `data-cms-point` and `data-cms-contribution` on a contribution's own element and `data-cms-point` and `data-cms-actions`, the ids separated by spaces, on an action point's toolbar, so they hold whatever the contribution renders; a nav entry is shown by the shell's navigation, not on a point's element, and is not found. `tests/Browser/Panel/PanelVisitTest.php` in this repository holds them to the panel in Chromium.

## Scaffolding

`cms:make:addon-ui <namespace>` scaffolds the panel UI of an installed addon into its package, from the contributions `cms:build` compiled from its manifest: first the generated types, as `cms:panel:types` does, then, each only where the addon has no such file, `package.json` with the SDK and its peers at the versions this release is built with, `tsconfig.json` on the shared base, `vite.config.ts` with the SDK's build plugin, `vitest.config.ts`, `eslint.config.js` on the SDK's rules, `.prettierrc`, the registration module `resources/panel/src/index.ts` with an entry per contribution that runs code and its test, a stub and a test per fill, form check and flow step, and `tests/Panel/PanelContributionsTest.php`, which runs the shared suite above. The ids module `resources/panel/src/ids.ts`, the ids the bundle registers code for, is written anew from the registry. A contribution of a kind without a stub, such as a decorator, gets a note instead. The scaffolded files pass the addon's `npm run typecheck`, `npm run lint` and `npm run test` as written, and `packages/generators/tests/Cli/MakeAddonUiCommandTest.php` holds them to that.

`cms:make:panel <fill|action|check|step> <namespace> <id>` scaffolds one contribution: the stub of its kind with a test on the SDK's conformance helper, with sample documents from the schemas of the point's props, the command and the data query, its entry in the registration and the ids module, and, for a contribution the manifest does not have yet, the line to add to the manifest's `PanelContributions`. A contribution `cms:build` compiled needs only its id; another needs `--point=<name>@<version>`, and a check, a step or an action `--command=<name>@<version>`, with `--query` for a fill's data query, `--severity` for a check, and `--position` and `--patch` for a step. An action runs no code, so it gets the manifest line alone. A registration or ids module the addon laid out otherwise is left alone, and the command says what to add by hand.

Both exit 0 when they wrote, 64 for a bad argument, an addon no installation has (`generate_panel_addon_unknown`), a point no package declares (`generate_panel_point_unknown`) or a kind the registry or the point does not take (`generate_panel_contribution_mismatch`), 66 for a command or query without a codec, 73 when a file cannot be written and 78 when the registry or the addon's `composer.json` cannot be read.
