<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedEntriesAggregates;
use Cbox\Cms\Core\Seeding\Domain\SeedAuthorizer;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use Override;

/*
 * Which types the seeder seeds and which fields it may write (GUARDRAILS 4.3, PRD 5.10), for the
 * shapes SeedPipelinePartsTest leaves out: types given in any order, a type without a validator
 * before others, required fields the rules name and the definition does not, extensions, and the
 * capabilities that make a type releasable.
 */

/**
 * SpreadType's definition under another name and id, with the capabilities given.
 */
function spreadAs(string $name, string $id, History $history = History::Full, Stages $stages = Stages::DraftRelease): TypeDefinition
{
    $spread = SpreadType::definition();

    return new TypeDefinition(TypeId::fromString($id), new TypeName($name), 1, new TypeCapabilities($history, $stages, $spread->capabilities->localization, false), $spread->extensions, $spread->fields);
}

/**
 * A validator of the type with the id whose rules are SpreadType's, with the given fields in place
 * of those of the same handle or added.
 *
 * @param  list<FieldRules>  $own
 * @param  list<FieldRules>  $extension
 */
function spreadValidator(string $id, array $own = [], array $extension = []): TypeValidator
{
    return new readonly class($id, $own, $extension) implements TypeValidator
    {
        /**
         * @param  list<FieldRules>  $own
         * @param  list<FieldRules>  $extension
         */
        public function __construct(private string $id, private array $own, private array $extension) {}

        #[Override]
        public function type(): TypeId
        {
            return TypeId::fromString($this->id);
        }

        #[Override]
        public function rules(): TypeRules
        {
            $rules = new SpreadType()->rules();
            $extensions = $rules->extensions;

            if ($this->extension !== []) {
                $extensions = [new ExtensionRules($extensions[0]->namespace, [...$extensions[0]->fields, ...$this->extension])];
            }

            $replaced = array_map(static fn (FieldRules $own): string => $own->handle->value, $this->own);
            $kept = array_values(array_filter($rules->fields, static fn (FieldRules $field): bool => ! in_array($field->handle->value, $replaced, true)));

            return new TypeRules([...$kept, ...$this->own], $extensions);
        }
    };
}

function catalogAccess(ClassificationAccess $access): AccessContext
{
    return new AccessContext(new ActorPrincipal(ActorId::fromString('0192a0c0-0000-7000-8000-0000000047c1'), [], IssuerKind::Service, ClassificationAccess::Sensitive), [], $access);
}

it('sorts the types by name whatever order the catalog gives them in', function (): void {
    $catalog = SeedTypes::of(
        [SpreadType::definition(), RulesType::definition()],
        new FakeTypeValidators(new SpreadType, new RulesType),
        ClassificationAccess::Internal,
    );

    expect(array_map(static fn (SeedableType $type): string => $type->definition->name->value, $catalog->types))->toBe(['test:rules', 'test:spread'])
        ->and($catalog->skipped)->toBe([]);
});

it('goes on to the types after one without a validator', function (): void {
    $catalog = SeedTypes::of(
        [spreadAs('test:aaa', '01936f5e-8a2b-7c3d-9e4f-0000000047d8'), RulesType::definition()],
        new FakeTypeValidators(new RulesType),
        ClassificationAccess::Internal,
    );

    expect(array_map(static fn (SeedableType $type): string => $type->definition->name->value, $catalog->types))->toBe(['test:rules'])
        ->and($catalog->skipped)->toBe(['The type test:aaa has no generated validator; run cms:generate.']);
});

it('skips a type with a required field it cannot write, naming the field by its address or its handle', function (): void {
    $id = '01936f5e-8a2b-7c3d-9e4f-0000000047d9';
    $required = static fn (string $handle): FieldRules => new FieldRules(new FieldHandle($handle), Presence::Required, [new Rule(RuleName::String)]);

    $unknown = SeedTypes::of([spreadAs('test:ghost', $id)], new FakeTypeValidators(spreadValidator($id, own: [$required('ghost')])), ClassificationAccess::Internal);
    $secret = SeedTypes::of([spreadAs('test:ghost', $id)], new FakeTypeValidators(spreadValidator($id, own: [$required('secret')])), ClassificationAccess::Internal);
    $extension = SeedTypes::of([spreadAs('test:ghost', $id)], new FakeTypeValidators(spreadValidator($id, extension: [$required('hidden')])), ClassificationAccess::Internal);
    $encrypted = SeedTypes::of([spreadAs('test:ghost', $id)], new FakeTypeValidators(spreadValidator($id, own: [$required('locked')])), ClassificationAccess::Sensitive);

    expect($unknown->types)->toBe([])
        ->and($unknown->skipped)->toBe(['The type test:ghost requires ghost, which the seeding actor cannot write: a field above its classification access internal, or stored encrypted.'])
        ->and($secret->skipped)->toBe(['The type test:ghost requires secret, which the seeding actor cannot write: a field above its classification access internal, or stored encrypted.'])
        ->and($extension->skipped)->toBe(['The type test:ghost requires hidden, which the seeding actor cannot write: a field above its classification access internal, or stored encrypted.'])
        ->and($encrypted->skipped)->toBe(['The type test:ghost requires locked, which the seeding actor cannot write: a field above its classification access sensitive, or stored encrypted.']);
});

it('releases the entries of a type with full history and a release stage only', function (): void {
    $types = [
        spreadAs('test:full', '01936f5e-8a2b-7c3d-9e4f-0000000047e1'),
        spreadAs('test:audit', '01936f5e-8a2b-7c3d-9e4f-0000000047e2', History::AuditOnly),
        spreadAs('test:none', '01936f5e-8a2b-7c3d-9e4f-0000000047e3', History::Full, Stages::None),
    ];
    $catalog = SeedTypes::of($types, new FakeTypeValidators(
        spreadValidator('01936f5e-8a2b-7c3d-9e4f-0000000047e1'),
        spreadValidator('01936f5e-8a2b-7c3d-9e4f-0000000047e2'),
        spreadValidator('01936f5e-8a2b-7c3d-9e4f-0000000047e3'),
    ), ClassificationAccess::Internal);

    expect(array_map(static fn (SeedableType $type): array => [$type->definition->name->value, $type->releasable], $catalog->types))
        ->toBe([['test:audit', false], ['test:full', true], ['test:none', false]]);
});

it('refuses another command than seed.entries, also one that carries a seed chunk', function (): void {
    $authorizer = new SeedAuthorizer(new FakeTypeCatalog(SpreadType::definition()));
    $chunk = new SeedEntries(spreadEntry(new FieldValues(new FieldMap)));
    $create = new CreateEntry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000047e1'), TypeId::fromString(SpreadType::ID), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues);

    expect($authorizer->authorize(catalogAccess(ClassificationAccess::Sensitive), new CommandName('entry.create'), $chunk, new SeedEntriesAggregates([], [])))
        ->toEqual(Authorization::refuse('The seeder runs only seed.entries, not entry.create.'))
        ->and($authorizer->authorize(catalogAccess(ClassificationAccess::Sensitive), new CommandName('seed.entries'), $create, new SeedEntriesAggregates([], [])))
        ->toEqual(Authorization::refuse('The seeder runs only seed.entries, not seed.entries.'));
});

it('checks every entry of a chunk, past one of a type it does not know, and the fields of each extension', function (): void {
    $authorizer = new SeedAuthorizer(new FakeTypeCatalog(SpreadType::definition()));
    $unknown = new SeededEntry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000047e3'), TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000047ff'), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues(new FieldMap(new NamedValue(new FieldHandle('secret'), new TextValue('x')))), false);
    $extension = new FieldValues(new FieldMap, new ExtensionFields(new FieldNamespace(SpreadType::NAMESPACE), new FieldMap(new NamedValue(new FieldHandle('extra'), new IntegerValue(3)))));
    $secret = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('secret'), new TextValue('x'))));
    $cleared = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('secret'), new NullValue)));
    $locked = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('locked'), new TextValue('x'))));
    $ask = static fn (FieldValues $fields, ClassificationAccess $access): Authorization => $authorizer->authorize(
        catalogAccess($access),
        new CommandName('seed.entries'),
        new SeedEntries($unknown, spreadEntry($fields)),
        new SeedEntriesAggregates([], []),
    );

    expect($ask($extension, ClassificationAccess::Public))->toEqual(Authorization::allow())
        ->and($ask($extension, ClassificationAccess::Internal))->toEqual(Authorization::allow())
        ->and($ask($cleared, ClassificationAccess::Internal))->toEqual(Authorization::allow())
        ->and($ask($secret, ClassificationAccess::Confidential))->toEqual(Authorization::allow())
        ->and($ask($secret, ClassificationAccess::Internal))->toEqual(Authorization::refuse('The seed entry 0192a0c0-0000-7000-8000-0000000047e4 writes the field "secret" of test:spread, classified confidential, which the actor may not write at its classification access internal.'))
        ->and($ask($locked, ClassificationAccess::Sensitive))->toEqual(Authorization::refuse('The seed entry 0192a0c0-0000-7000-8000-0000000047e4 writes the field "locked" of test:spread, classified public and stored encrypted, which the actor may not write at its classification access sensitive.'));
});

it('refuses an extension field above the actor\'s access, by its address', function (): void {
    $definition = SpreadType::definition();
    $fields = array_map(
        static fn (FieldDefinition $field): FieldDefinition => $field->namespace instanceof FieldNamespace && $field->handle->value === 'extra'
            ? new FieldDefinition($field->namespace, $field->handle, $field->fieldType, ClassificationAccess::Personal, false, false, false, false, false, $field->column)
            : $field,
        $definition->fields,
    );
    $authorizer = new SeedAuthorizer(new FakeTypeCatalog(new TypeDefinition($definition->id, $definition->name, 1, $definition->capabilities, $definition->extensions, $fields)));
    $extension = new FieldValues(new FieldMap, new ExtensionFields(new FieldNamespace(SpreadType::NAMESPACE), new FieldMap(new NamedValue(new FieldHandle('extra'), new IntegerValue(3)))));

    expect($authorizer->authorize(catalogAccess(ClassificationAccess::Internal), new CommandName('seed.entries'), new SeedEntries(spreadEntry($extension)), new SeedEntriesAggregates([], [])))
        ->toEqual(Authorization::refuse('The seed entry 0192a0c0-0000-7000-8000-0000000047e4 writes the field "ext.acme.extra" of test:spread, classified personal, which the actor may not write at its classification access internal.'));
});

function spreadEntry(FieldValues $fields): SeededEntry
{
    return new SeededEntry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000047e4'), TypeId::fromString(SpreadType::ID), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), $fields, false);
}
