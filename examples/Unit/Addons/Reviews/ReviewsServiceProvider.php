<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Examples\Unit\Build\Notes\PublishNote;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the addon acme/cms-reviews. Its scan root holds the addon's hook, and
 * its manifest says what the addon does: it is named reviews, needs the core API 1.0, reads fields
 * up to internal, may validate the notes package's note.publish, and contributes the field type
 * reviews:stars.
 */
final class ReviewsServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-reviews', __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: 'acme/cms-reviews',
            namespace: new AddonNamespace('reviews'),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__.'/docs',
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Internal),
            hooks: [new AllowedHook(PublishNote::class, Phase::Validate)],
            schema: new SchemaContributions(fieldTypes: [new ContributedFieldType('reviews:stars')]),
        );
    }
}
