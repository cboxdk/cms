---
title: Panel points
weight: 50
description: "Declare an extension point of the panel with #[PanelPoint] on its props class, the rules for its kind, and the panel registry cms:build writes to panel.php."
---

# Panel points

<!-- extension-point: Cbox\Cms\Contracts\PanelPoints\PanelPoint -->

A panel point is a place in the panel that contributions extend: a slot that renders components, an action button, a decorator around a default, a replacement of a default, a check on a command form, and so on (PRD 13.4). A point is declared on the class of its props with `#[PanelPoint]`, and `cms:build` writes it to the panel registry, `panel.php` in `bootstrap/cache/cms/`, under its id `<name>@<version>`, such as `account.me.sections@1`. `#[PanelPoint]` and every type in `Cbox\Cms\Contracts\PanelPoints` are `#[Experimental]`.

The class is the point's props: its public properties are what a contribution receives, and a point without props is declared on a class without properties. It is a `final readonly class`, so the props cannot change once the panel hands them out, and it carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`, which is the point's stability. An `#[Internal]` point is the core's own wiring: no addon contributes to it, and it is not an extension point.

## The attribute

| Argument | Value |
|---|---|
| `name` | The point's name: at least two dot-separated segments of lowercase letters, digits and single hyphens, each starting with a letter, at most 64 characters, such as `grants.list.row-actions`. |
| `version` | An integer from 1. A breaking change to the props is the next version, and both versions are points of their own. |
| `kind` | A case of `PointKind`: `Slot`, `Action`, `Nav`, `Page`, `Decorator`, `Replacement`, `FormCheck`, `FlowStep`, `Observer`, `Provider`, `Theme` or `Data`. |
| `page` | The page that renders the point: dot-separated segments of the same form, one or more, such as `account.me` or `shell`. |
| `since` | The release of the panel API the point arrived in, `<major>.<minor>`, such as `1.0`. |
| `label` | The translation key of the point's name in the panel's catalogue, such as `panel.points.account_me_sections`. |
| `region` | For a slot, and only a slot: the `Region` it sits in. `Toolbar`, `Columns` and `Tabs` take descriptors the panel renders; only `Sections` and `Aside` take markup of their own. |
| `multiplicity` and `max` | How many contributions the panel renders: `Multiplicity::Many` (the default), `Multiplicity::Max` with `max` from 1, or `Multiplicity::Exclusive`, which a replacement is and only a replacement is. |
| `ownership` and `keyedBy` | For a replacement, and only a replacement: which keys an addon may replace (`Ownership::Own` for keys it owns, `Ownership::Any` for any) and what a key is (`ReplacementKey::FieldType`, `ValueClass` or `Command`). |
| `tightens` | For a decorator, and only a decorator: the props of the default it may tighten, each once: `Tighten::DisabledReason`, `Tighten::Description` and `Tighten::ToneTowardsDanger`. |

An argument that breaks these rules throws `InvalidPanelPoint`, and `cms:build` reports it as `registry_invalid_attribute`. The build also refuses two classes that declare one name and version (`registry_duplicate_panel_point`), a props class without exactly one stability attribute (`registry_panel_point_without_stability`) and one that is not a `final readonly class` (`registry_not_final_readonly`), and writes nothing.

## The panel registry

`panel.php` holds every point sorted by name and then version, each with its id, kind, page, region, multiplicity, `max`, ownership, key, tightening props, stability, release, label, props class and package, and the contributions to it in the order the panel renders them: priority with the lowest first, then the addon's namespace, then the contribution's id. A contribution's id is `<namespace>.<local>`, such as `approvals.badge`, with `cms` for the core's own, and its scope narrows it to pages, command forms (`<command>@<version>`), types, field types and the permission a viewer must hold. Contributions come from the addon manifests, so the registry holds none until a manifest declares them.

`cms:panel:points [selector]` lists the points, all of them or those a page, a point's name or an id selects, and `cms:panel:fills <point>` lists the contributions to one point in render order. Both take `--json`, and [inspecting the installation](../developers/inspecting.md) describes their output.

## Example

The package `acme/cms-reviews` declares its scan root, as for any [build declaration](build-declarations.md):

<!-- example-file: examples/Unit/Panel/Reviews/ReviewsServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-reviews. Its scan root is the directory it lies in,
 * so cms:build registers the panel points declared next to it.
 */
final class ReviewsServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-reviews', __DIR__)];
    }
}
```

A slot in the sections of a review's page, with the review and its stars as props:

<!-- example-file: examples/Unit/Panel/Reviews/ReviewSectionsV1.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of the sections of a review's page, a slot other addons fill with sections of their
 * own. The class is the point's props, and #[Experimental] its stability.
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.detail.sections',
    version: 1,
    kind: PointKind::Slot,
    page: 'reviews.detail',
    since: '1.0',
    label: 'reviews.points.detail_sections',
    region: Region::Sections,
)]
final readonly class ReviewSectionsV1
{
    public function __construct(
        public string $review,
        public int $stars,
    ) {}
}
```

The submit button of the review form, which decorators may tighten:

<!-- example-file: examples/Unit/Panel/Reviews/ReviewSubmitV1.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The props of the submit button of the review form, which decorators may tighten: disable it with
 * a reason, append to its description, or move its tone towards danger.
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.form.submit',
    version: 1,
    kind: PointKind::Decorator,
    page: 'reviews.form',
    since: '1.0',
    label: 'reviews.points.form_submit',
    tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger],
)]
final readonly class ReviewSubmitV1
{
    public function __construct(public string $command) {}
}
```

The test builds the registry with the package and reads the points back, from `panel.php` and through the inspecting commands:

<!-- example: examples/Unit/Panel/PanelPointsTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Reviews\ReviewSectionsV1;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewSubmitV1;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build writes the panel points of the reviews package to panel.php under their ids, and
 * cms:panel:points and cms:panel:fills read them back.
 */
final class PanelPointsTest extends BuildTestCase
{
    #[Test]
    public function it_registers_a_panel_point_under_its_id(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        $panel = require $this->registryFile('panel');
        self::assertIsArray($panel);
        self::assertSame('panel', $panel['registry']);
        self::assertIsArray($panel['entries']);
        self::assertContains([
            'class' => ReviewSectionsV1::class,
            'fills' => [],
            'id' => 'reviews.detail.sections@1',
            'keyed_by' => null,
            'kind' => 'slot',
            'label' => 'reviews.points.detail_sections',
            'max' => null,
            'multiplicity' => 'many',
            'ownership' => null,
            'package' => 'acme/cms-reviews',
            'page' => 'reviews.detail',
            'region' => 'sections',
            'since' => '1.0',
            'stability' => 'experimental',
            'tightens' => [],
        ], $panel['entries']);

        // Every point of the package is there, each under its own id.
        $points = array_column($panel['entries'], 'class', 'id');
        self::assertSame(ReviewSubmitV1::class, $points['reviews.form.submit@1'] ?? null);
    }

    #[Test]
    public function it_lists_the_points_of_a_page_and_the_contributions_to_a_point(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        self::assertSame(0, app(Kernel::class)->call('cms:panel:points', ['selector' => 'reviews.form']));
        self::assertStringContainsString(
            'reviews.form.submit@1  decorator, renders many, tightens disabled_reason and description and tone_towards_danger  page reviews.form  experimental since 1.0  0 contributions',
            app(Kernel::class)->output(),
        );

        // No addon contributes to the point yet, so the panel renders none.
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@1']));
        self::assertSame("reviews.detail.sections@1: no contributions.\n", app(Kernel::class)->output());

        // A point the registry does not hold is a usage error.
        self::assertSame(64, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@2']));
    }
}
```
