---
title: Stability and deprecation
weight: 18
description: "What the panel API promises an addon: the stability levels and the experimental opt-in, the panel API version, point versions and their downcasts, the compatibility lock, the API reports, and how a point or an export is deprecated and promoted."
---

# Stability and deprecation

<!-- extension-point: Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest -->

Every panel point is public API that an addon builds on, so the panel says for each point, each token and each export of the SDK and the kit how far an addon can rely on it, and holds itself to that with tests. Every point, token and export of block B1 is experimental (decision D4).

## Stability levels

| Level | Where it is stated | What it promises |
|---|---|---|
| Stable | `#[Stable]` on a point's props class, `stable` on a token in `js/ui-kit/tokens.json`, `@stable` in an export's TSDoc | follows the panel API version: it changes only in a major version, and a point's props only gain optional members |
| Experimental | `#[Experimental]`, `experimental`, `@experimental` | may change in a minor version of the panel API; an addon opts in to it |
| Internal | `#[Internal]`, `internal` | for the panel alone: an internal point takes no contribution, an internal token is set only by the kit, and the SDK does not export internal API |

An addon opts in to an experimental point twice: its manifest lists the point in `acceptsExperimental`, or `cms:build` refuses the contribution with `registry_panel_experimental_not_accepted`, and its code imports the point's types from `@cboxdk/cms-panel/experimental`, which is the only subpath that exports experimental API. `cms:build` also warns about each contribution to an experimental point with `registry_panel_point_experimental`, so the installation sees what may change.

## The panel API version

`PanelApiVersion` is the version of the panel's API for addon UI, separate from the Composer version and from `CoreApiVersion`. It covers the stable points and their props, the host API, the stable tokens, the kit's props, the shared modules of the import map and the React major version. A minor version only adds; a major version may break. An addon names the version it needs in its manifest, `sdk: new PanelApiVersion(1, 0)`, read as `^1.0`, and `cms:build` refuses it with `registry_incompatible_panel_api` when the panel does not satisfy it. `PanelApiVersion::current()` is 1.0, and the SDK's `PANEL_API_VERSION` is the same version, which `js/panel-sdk/tests/define-panel-addon.test.ts` holds; the host renders nothing of an addon whose registration was made with another major version or a newer minor.

## Versions and downcasts

A breaking change to a point's props is the point's next version, `<name>@2`, and both are points of their own. The panel builds the props of the newest version only, and a contribution to an older version keeps working: the props class of every older version implements `DowncastsFromNewest`, with the newest props class as its template, and builds its own props from the newest version's in `downcast()`. A downcast reads the newest props and nothing else. When a version after it arrives, each older version's downcast moves to it. `cms:build` refuses an older version without one with `registry_panel_point_without_downcast` and writes nothing, and `Cbox\Cms\Core\Registry\Domain\PointDowncasts` gives the props of any version of a point from the newest version's:

<!-- example-file: examples/Unit/Panel/Reviews/ReviewSubmitV2.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The props of the submit button of the review form, version 2: the command and its version as
 * two members, a breaking change to version 1's props, which keeps working through its downcast.
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.form.submit',
    version: 2,
    kind: PointKind::Decorator,
    page: 'reviews.form',
    since: '1.1',
    label: 'reviews.points.form_submit',
    tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger],
)]
final readonly class ReviewSubmitV2
{
    public function __construct(
        public string $command,
        public int $commandVersion,
    ) {}
}
```

The test builds the registry and asks for the props of both versions:

<!-- example: examples/Unit/Panel/PointDowncastTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\PointDowncasts;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewSubmitV1;
use Examples\Unit\Panel\Reviews\ReviewSubmitV2;
use PHPUnit\Framework\Attributes\Test;

/**
 * The review form's submit button has two versions. The panel builds the props of the newest,
 * and a contribution to version 1 gets its props through version 1's downcast.
 */
final class PointDowncastTest extends BuildTestCase
{
    #[Test]
    public function it_builds_the_props_of_an_older_version_from_the_newest_versions(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        $downcasts = new PointDowncasts(app(CompiledRegistry::class));
        $newest = new ReviewSubmitV2('review.create', 1);

        self::assertEquals(new ReviewSubmitV1('review.create'), $downcasts->props(PointId::fromString('reviews.form.submit@1'), $newest));
        self::assertSame($newest, $downcasts->props(PointId::fromString('reviews.form.submit@2'), $newest));
    }
}
```

## The compatibility lock

An addon written against a stable point keeps working for the point's version, so a `#[Stable]` point's props may only gain an optional member. `packages/panel/resources/points.lock.json` holds, for each stable point, its schema's path, the SHA-256 of its contract and the contract itself: the schema as canonical JSON without `title`, `description`, `examples` and `$comment`. `composer generate:protocol` compares each stable point's schema with the committed lock before it writes anything, and refuses with `generate_schema_invalid` a removed or renamed member, a member made required or optional, an added required member, any change to an existing member's schema, narrowing or widening, and a stable point that leaves the lock. A wording change and an added optional member pass, and it records them. Give anything else to the point's next version, with a downcast from it. Every point is experimental until it is promoted, so the lock holds no point yet.


## The API reports

`js/panel-sdk/api/cms-panel.api.md` is the API Extractor report of every subpath of the SDK, and `js/ui-kit/api/cms-ui-kit.api.md` the kit's, each export with its stability tag. A test of gate 5 fails when a report is not what the package exports now, so a change to what addons may rely on is recorded with its version decision: run `npm run api:report`, review the diff, and decide the version. Adding an export is a minor version of the panel API; removing, renaming or narrowing a stable one is a major version. [Panel SDK](sdk.md#the-api-report) has the details.

## Deprecation

A point on its way out names its deprecation in its attribute, `deprecated: new PointDeprecation(since: '1.2', removeIn: '2.0', replacement: '<name>@<version>')`:

- `cms:build` warns about every contribution to it with `registry_panel_point_deprecated`, naming the release it goes in and its replacement, and still compiles it.
- `cms:panel:points` shows `deprecated since 1.2, removed in 2.0, replaced by <id>` below the point, and `deprecated` in its JSON.
- The replacement is usually the point's next version, so a contribution to the old version keeps working through its downcast until the removal.
- The point's page in these docs shows how to move to the replacement.

A deprecated export of the SDK or the kit carries `@deprecated` in its TSDoc, and the lint of an addon's repository, `@cboxdk/cms-panel/eslint`, reports every use of it with `@typescript-eslint/no-deprecated`. A stable point keeps working for at least a major version and twelve months after it is deprecated (decision D14).

## Promotion to stable

A point becomes stable only when it has proven its contract (section 6 of the panel extension architecture): it is used by the workbench's fixture addon and by one real addon, it has been experimental for one minor release, it has a page here with a running example and a story, it passes the conformance suite of its kind, and the change is recorded in `CHECKS-LOG.md`. Its props class then carries `#[Stable]`, `composer generate:protocol` adds it to `points.lock.json` and moves its types from `/experimental` to `/extend`, and from then on only the rules above change it.
