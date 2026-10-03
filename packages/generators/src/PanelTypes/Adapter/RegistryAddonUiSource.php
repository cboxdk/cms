<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Adapter;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Boundary\JsonSchemaShapes;
use Cbox\Cms\Generators\PanelTypes\Boundary\PackageRoot;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\AddonUi;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ContractShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PointType;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;
use Closure;
use Override;

/**
 * An installed addon's UI from the registry cms:build compiled (PRD 13.4): its contributions that
 * run code, from the fills of every panel point, with the point's props class and stability; the
 * commands its UI may issue, from addons.php; the JSON Schemas of the data queries' results and
 * the commands' documents, from their generated codecs (CommandCodecs, QueryCodecs); and the
 * directory of its Composer package.
 */
#[Internal]
final readonly class RegistryAddonUiSource implements AddonUiSource
{
    /**
     * @param  Closure(string): ?string  $packageRoot  the directory of a Composer package, PackageRoot::of() by default
     */
    public function __construct(
        private RegistryCache $registry,
        private CommandCodecs $commands,
        private QueryCodecs $queries,
        private ?Closure $packageRoot = null,
    ) {}

    #[Override]
    public function addon(AddonNamespace $namespace): AddonUi
    {
        try {
            $registry = $this->registry->read();
        } catch (RegistryCacheMissing|MalformedRegistryCache $unreadable) {
            throw GenerationFailed::because(GenerateErrorCode::RegistryUnreadable, sprintf(
                'The registry cms:build compiles cannot be read: %s Run cms:build, then cms:panel:types %s again.',
                $unreadable->getMessage(),
                $namespace->value,
            ));
        }

        $addon = $registry->addon($namespace);

        if (! $addon instanceof AddonEntry) {
            throw GenerationFailed::because(GenerateErrorCode::PanelAddonUnknown, sprintf(
                'No installed addon has the namespace %s. The addons cms:build compiled are: %s.',
                $namespace->value,
                $registry->addons === [] ? 'none' : implode(', ', array_map(static fn (AddonEntry $entry): string => $entry->namespace->value, $registry->addons)),
            ));
        }

        $root = ($this->packageRoot ?? PackageRoot::of(...))($addon->package);

        if ($root === null) {
            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf(
                'Composer did not install the package %s of the addon %s, so its directory is unknown. Install it, then run cms:panel:types again.',
                $addon->package,
                $namespace->value,
            ));
        }

        $issues = [];

        foreach ($addon->issues as $issued) {
            $issues[] = $this->command($issued->command);
        }

        return new AddonUi($namespace, $root, $this->contributions($registry, $namespace), $issues);
    }

    /**
     * @return list<UiContribution>
     *
     * @throws GenerationFailed
     */
    private function contributions(CompiledRegistry $registry, AddonNamespace $namespace): array
    {
        $contributions = [];

        foreach ($registry->panel as $point) {
            foreach ($point->fills as $fill) {
                if ($fill->addon()->value !== $namespace->value || ! $fill->declaration->runsCode()) {
                    continue;
                }

                $declaration = $fill->declaration;
                $contributions[] = new UiContribution(
                    $fill->contribution,
                    $declaration->kind(),
                    $this->pointType($point),
                    $fill->query instanceof CommandRef ? $this->result($fill->query) : null,
                    $fill->command instanceof CommandRef ? $this->command($fill->command) : null,
                    $declaration instanceof DecoratorContribution ? array_map(static fn (Tighten $tighten): string => $tighten->value, $declaration->tightens) : [],
                    $declaration instanceof FlowStep ? $declaration->patches : [],
                );
            }
        }

        return $contributions;
    }

    private function pointType(PanelPointEntry $point): PointType
    {
        $separator = strrpos($point->class, '\\');

        return new PointType(
            $point->id(),
            $separator === false ? $point->class : substr($point->class, $separator + 1),
            $point->stability === PointStability::Stable,
        );
    }

    /**
     * @throws GenerationFailed
     */
    private function command(CommandRef $ref): ContractShape
    {
        $codec = $this->commands->find($ref->name, $ref->version);

        if (! $codec instanceof CommandCodec) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The command %s has no codec, so its document has no JSON Schema to type. Give the command a codec and run cms:build again.',
                $ref->toString(),
            ));
        }

        return new ContractShape($ref, JsonSchemaShapes::read($codec->schema->json, 'the schema of the command '.$ref->toString()));
    }

    /**
     * @throws GenerationFailed
     */
    private function result(CommandRef $ref): ContractShape
    {
        $codec = $this->queries->find($ref->name, $ref->version);

        if (! $codec instanceof QueryCodec) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The data query %s has no codec, so its result has no JSON Schema to type. Give the query a codec and run cms:build again.',
                $ref->toString(),
            ));
        }

        return new ContractShape($ref, JsonSchemaShapes::read($codec->resultSchema->json, 'the schema of the result of the query '.$ref->toString()));
    }
}
