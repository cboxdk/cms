<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Override;

/**
 * The authorization of the seeder's pipeline (PRD 5.10, 6.2 phase 2), which runs seed.entries only,
 * as the service actor an operator names in cbox-cms.seeding.service_actor, from the console. It
 * refuses every other command, and a chunk that writes a field the actor may not: one above the
 * access context's classification access, or one stored encrypted. Where the entries go is held by
 * the actor's regions: a home node the regions do not reach reads as absent, which the kernel
 * refuses, and the row level security of the entries refuses the write.
 */
#[Internal]
final readonly class SeedAuthorizer implements CommandAuthorizer
{
    public const string COMMAND = 'seed.entries';

    public function __construct(private TypeCatalog $types) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        if (! $input instanceof SeedEntries || $command->value !== self::COMMAND) {
            return Authorization::refuse(sprintf('The seeder runs only %s, not %s.', self::COMMAND, $command->value));
        }

        foreach ($input->entries as $entry) {
            $type = $this->types->find($entry->type);

            if (! $type instanceof TypeDefinition) {
                continue;
            }

            $maps = [[null, $entry->fields->own]];

            foreach ($entry->fields->extensions as $extension) {
                $maps[] = [$extension->namespace, $extension->fields];
            }

            foreach ($maps as [$namespace, $map]) {
                $refused = $this->refused($type, $namespace, $map, $access);

                if ($refused instanceof FieldDefinition) {
                    return Authorization::refuse(sprintf(
                        'The seed entry %s writes the field "%s" of %s, classified %s%s, which the actor may not write at its classification access %s.',
                        $entry->entry->toString(),
                        $refused->address(),
                        $type->name->value,
                        $refused->classification->value,
                        $refused->encrypted ? ' and stored encrypted' : '',
                        $access->classificationAccess->value,
                    ));
                }
            }
        }

        return Authorization::allow();
    }

    private function refused(TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $map, AccessContext $access): ?FieldDefinition
    {
        foreach ($map->fields as $field) {
            $definition = $type->field($namespace, $field->handle);

            if ($definition instanceof FieldDefinition && ! $field->value instanceof NullValue && ! FieldValueGenerator::writable($definition, $access->classificationAccess)) {
                return $definition;
            }
        }

        return null;
    }
}
