<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleCodecV1;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approvals addon's dist/panel-manifest.json is a document of panel-bundle.v1.json: its
 * generated codec reads it, and every file it lists has the SHA-384 it says.
 */
final class PanelBundleManifestTest extends TestCase
{
    #[Test]
    public function it_reads_the_bundle_manifest_and_every_file_has_its_hash(): void
    {
        $directory = __DIR__.'/Approvals/dist';
        $json = file_get_contents($directory.'/panel-manifest.json');
        self::assertIsString($json);

        $manifest = new PanelBundleCodecV1()->decode($json, ClassificationAccess::Public);

        self::assertSame('addon.js', $manifest->entry->value);
        self::assertSame(['approvals.badge'], array_map(static fn (ContributionId $id): string => $id->value, $manifest->contributions));

        foreach ($manifest->files as $file) {
            $bytes = file_get_contents($directory.'/'.$file->path->value);
            self::assertIsString($bytes);
            self::assertTrue(BundleIntegrity::of($bytes)->equals($file->integrity), $file->path->value);
        }

        self::assertSame([BundleFileKind::Style, BundleFileKind::Script, BundleFileKind::Script], array_map(static fn (BundleFile $file): BundleFileKind => $file->kind, $manifest->files));
    }
}
