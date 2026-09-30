<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\DuplicateFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\AddonFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\InvalidFieldTypeName;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use stdClass;

/**
 * The field types cms:generate reads blueprint files with (PRD 11.12, 13.1, 13.3): the core's,
 * through CoreFieldTypes, and each addon's that schema.php lists, which cms:build compiled from the
 * addons' manifests. For every addon with field types it makes the class of the manifest's
 * FieldTypeContributor through the container and registers what it returns in the addon's
 * namespace, so a field type of an unregistered namespace stays generate_unknown_field_type.
 *
 * Each of these is the configuration's problem, generate_invalid_config, reported together: a
 * registry cache that is missing or damaged, a contributor that cannot be made or does not
 * implement FieldTypeContributor, one that returns other field types than its manifest lists or
 * one twice, and an options schema that cannot be used.
 */
#[Internal]
final readonly class RegisteredFieldTypes
{
    /**
     * @param  Closure(): CompiledRegistry  $registry  reads the compiled registry
     * @param  Closure(string): mixed  $make  makes a class through the container
     */
    public function __construct(
        private Closure $registry,
        private Closure $make,
        private OptionsSchemas $schemas = new OptionsSchemas,
    ) {}

    public static function of(Application $app): self
    {
        return new self(
            static fn (): CompiledRegistry => $app->make(CompiledRegistry::class),
            static fn (string $class): mixed => $app->make($class),
        );
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public function registry(): FieldTypeRegistry
    {
        try {
            $schema = ($this->registry)()->schema;
        } catch (RegistryCacheMissing|MalformedRegistryCache $unreadable) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The compiled registry, which lists the addons\' field types, cannot be read: %s',
                $unreadable->getMessage(),
            ), $unreadable);
        }

        $addons = [];
        $problems = [];

        foreach ($schema as $entry) {
            $contributions = $this->contributions($entry, $problems);

            if ($contributions !== null) {
                $addons[] = new AddonFieldTypes(new Owner($entry->namespace->value), $contributions);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        try {
            return new FieldTypeRegistry(new CoreFieldTypes, ...$addons);
        } catch (InvalidFieldTypeName|DuplicateFieldType $invalid) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, $invalid->getMessage(), $invalid);
        }
    }

    /**
     * The field types of the addon's contributor, or null when the addon has none or its
     * contributor cannot be used; each reason is added to the problems.
     *
     * @param  list<GenerationProblem>  $problems
     * @return ?list<FieldTypeContribution>
     */
    private function contributions(SchemaEntry $entry, array &$problems): ?array
    {
        $class = $entry->fieldTypeContributor;

        if ($class === null) {
            return null;
        }

        $addon = sprintf('addon "%s" (%s)', $entry->namespace->value, $entry->package);

        try {
            $contributor = ($this->make)($class);
        } catch (BindingResolutionException $unresolvable) {
            $problems[] = $this->problem(sprintf('The field type contributor %s of %s cannot be made: %s', $class, $addon, $unresolvable->getMessage()));

            return null;
        }

        if (! $contributor instanceof FieldTypeContributor) {
            $problems[] = $this->problem(sprintf('The field type contributor %s of %s does not implement %s.', $class, $addon, FieldTypeContributor::class));

            return null;
        }

        $contributions = $contributor->fieldTypes();
        $returned = array_map(static fn (FieldTypeContribution $contribution): string => $contribution->name()->value, $contributions);
        $listed = array_map(static fn (ContributedFieldType $type): string => $type->value, $entry->fieldTypes);
        $sorted = $returned;
        sort($sorted, SORT_STRING);

        if ($sorted !== $listed) {
            $problems[] = $this->problem(sprintf(
                'The field type contributor %s of %s returns the field types %s, and the manifest lists %s. Return each field type the manifest lists, once, and run `cms:build` after changing the manifest.',
                $class,
                $addon,
                $returned === [] ? 'none' : implode(', ', $returned),
                implode(', ', $listed),
            ));

            return null;
        }

        $usable = true;

        foreach ($contributions as $contribution) {
            try {
                $this->schemas->check($contribution->name()->value, $contribution->optionsSchema(), new stdClass, new SourceLocation('', ''));
            } catch (GenerationFailed $unusable) {
                array_push($problems, ...$unusable->problems);
                $usable = false;
            }
        }

        return $usable ? $contributions : null;
    }

    private function problem(string $message): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::InvalidConfig, $message);
    }
}
