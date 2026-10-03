<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\StoryPoint;
use Cbox\Cms\Generators\PanelStories\Domain\PanelPointSource;
use Cbox\Cms\Generators\Protocol\Boundary\SampleProps;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Override;

/**
 * The installation's panel points from the registry cms:build wrote (panel.php), each with the
 * props schema a module's schema directory holds for it, `<name>.v<version>.json`, and the sample
 * props made from that schema as composer generate:protocol makes them, and with the
 * contributions the build left enabled, in render order.
 */
#[Internal]
final readonly class RegistryPanelPointSource implements PanelPointSource
{
    /**
     * @param  list<PointSchemaDirectory>  $schemas  the directories of the points' props schemas
     */
    public function __construct(
        private RegistryCache $registry,
        private array $schemas,
    ) {}

    #[Override]
    public function points(): array
    {
        try {
            $registry = $this->registry->read();
        } catch (RegistryCacheMissing|MalformedRegistryCache $unreadable) {
            throw GenerationFailed::because(GenerateErrorCode::RegistryUnreadable, sprintf(
                'The registry cms:build compiles cannot be read: %s Run cms:build, then cms:panel:stories again.',
                $unreadable->getMessage(),
            ));
        }

        return array_map($this->point(...), $registry->panel);
    }

    /**
     * @throws GenerationFailed
     */
    private function point(PanelPointEntry $entry): StoryPoint
    {
        $file = sprintf('%s.v%d.json', $entry->declaration->name, $entry->declaration->version);
        $schema = null;
        $sample = null;

        foreach ($this->schemas as $directory) {
            $path = rtrim($directory->path, '/').'/'.$file;
            $json = is_file($path) ? LocalFile::contents($path) : null;

            if ($json !== null) {
                $schema = rtrim($json);
                $sample = SampleProps::literal(SampleProps::of($json, $path));

                break;
            }
        }

        return new StoryPoint(
            $entry->declaration,
            $entry->class,
            $entry->stability->value,
            $sample,
            $schema,
            array_values(array_filter($entry->fills, static fn (PanelFill $fill): bool => $fill->enabled)),
        );
    }
}
