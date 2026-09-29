<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\RecordContracts;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;

/*
 * The record contract of a type (PRD 8.9): the entry's id, the owner's fields and the extension
 * fields under ext, with the kinds, presence, classifications and rules of the type descriptor
 * (PRD 11.12), and generated names that follow from the handles alone, so two handles that give
 * the same PHP name are refused.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * @return array<string, CodecProperty>
 */
function propertiesByKey(CodecObject $object): array
{
    $properties = [];

    foreach ($object->properties as $property) {
        $properties[$property->key] = $property;
    }

    return $properties;
}

function propertyObject(CodecProperty $property): CodecObject
{
    $value = $property->value->item ?? $property->value;

    return $value->object ?? throw new LogicException($property->key.' holds no object.');
}

it('maps the comprehensive example into the record contract', function (): void {
    $contract = RecordContracts::of(ComprehensiveExample::compile()->types[0]);
    $root = propertiesByKey($contract->root);
    $describe = static fn (CodecProperty $property): array => [
        $property->name,
        $property->value->kind,
        $property->value->item?->kind,
        $property->required,
        $property->classification,
        array_map(static fn (ValidationRule $rule): string => $rule->name->value, $property->value->rules),
    ];

    expect($contract->root->className)->toBe('ShopProductV1')
        ->and($contract->codecClass)->toBe('ShopProductCodecV1')
        ->and($contract->version)->toBe(1)
        ->and(array_keys($root))->toBe(['body', 'care', 'checked_at', 'cms_id', 'colour', 'dimensions', 'discontinued', 'ext', 'launch_date', 'name', 'price', 'purchase_price', 'stock', 'summary', 'supplier', 'support_email', 'tags', 'weight'])
        ->and(array_map($describe, $root))->toBe([
            'body' => ['body', CodecKind::PortableText, null, false, ClassificationAccess::Public, ['portable_text', 'styles', 'marks', 'lists', 'links']],
            'care' => ['care', CodecKind::PortableText, null, false, ClassificationAccess::Public, ['portable_text', 'styles', 'marks', 'lists', 'links']],
            'checked_at' => ['checkedAt', CodecKind::Datetime, null, false, ClassificationAccess::Internal, ['datetime', 'min']],
            'cms_id' => ['cmsId', CodecKind::Id, null, true, null, []],
            'colour' => ['colour', CodecKind::Choice, null, true, ClassificationAccess::Public, ['in']],
            'dimensions' => ['dimensions', CodecKind::List, CodecKind::Object, false, ClassificationAccess::Public, ['list', 'min_items', 'max_items']],
            'discontinued' => ['discontinued', CodecKind::Boolean, null, false, ClassificationAccess::Public, ['boolean']],
            'ext' => ['ext', CodecKind::Object, null, true, null, []],
            'launch_date' => ['launchDate', CodecKind::Date, null, false, ClassificationAccess::Public, ['date', 'min', 'max']],
            'name' => ['name', CodecKind::Text, null, true, ClassificationAccess::Public, ['string', 'min_length', 'max_length']],
            'price' => ['price', CodecKind::Decimal, null, true, ClassificationAccess::Public, ['decimal', 'min', 'max']],
            'purchase_price' => ['purchasePrice', CodecKind::Decimal, null, false, ClassificationAccess::Confidential, ['decimal']],
            'stock' => ['stock', CodecKind::Integer, null, false, ClassificationAccess::Internal, ['integer', 'min', 'max']],
            'summary' => ['summary', CodecKind::Text, null, false, ClassificationAccess::Internal, ['string', 'max_length']],
            'supplier' => ['supplier', CodecKind::Object, null, false, ClassificationAccess::Confidential, ['object']],
            'support_email' => ['supportEmail', CodecKind::Text, null, false, ClassificationAccess::Public, ['string', 'max_length', 'format']],
            'tags' => ['tags', CodecKind::List, CodecKind::Choice, false, ClassificationAccess::Public, ['list', 'distinct', 'min_items', 'max_items']],
            'weight' => ['weight', CodecKind::Integer, null, false, ClassificationAccess::Public, ['integer', 'min']],
        ])
        ->and($root['cms_id']->value->class)->toBe(EntryId::class)
        ->and($root['tags']->value->item?->rules)->toEqual([new ValidationRule(ValidationRuleName::In, ['new', 'sale', 'eco'])]);

    $supplier = propertyObject($root['supplier']);
    $dimensions = propertyObject($root['dimensions']);
    $ext = propertyObject($root['ext']);
    $app = propertyObject(propertiesByKey($ext)['app']);

    expect($supplier->className)->toBe('ShopProductV1Supplier')
        ->and(array_map($describe, propertiesByKey($supplier)))->toBe([
            'company' => ['company', CodecKind::Text, null, true, null, ['string', 'max_length']],
            'notes' => ['notes', CodecKind::Text, null, false, null, ['string', 'max_length']],
            'website' => ['website', CodecKind::Text, null, false, null, ['string', 'max_length', 'format']],
        ])
        ->and($dimensions->className)->toBe('ShopProductV1Dimensions')
        ->and($ext->className)->toBe('ShopProductV1Ext')
        ->and($app->className)->toBe('ShopProductV1ExtApp')
        ->and(array_map($describe, propertiesByKey($app)))->toBe([
            'name' => ['name', CodecKind::Text, null, false, ClassificationAccess::Public, ['string', 'max_length']],
            'tax_code' => ['taxCode', CodecKind::Text, null, false, ClassificationAccess::Internal, ['string', 'max_length']],
        ])
        ->and($contract->root->classified())->toBeTrue()
        ->and($supplier->classified())->toBeFalse()
        ->and($ext->classified())->toBeTrue()
        ->and(array_map(static fn (CodecObject $object): string => $object->className, $contract->root->objects()))
        ->toBe(['ShopProductV1', 'ShopProductV1Dimensions', 'ShopProductV1Ext', 'ShopProductV1ExtApp', 'ShopProductV1Supplier']);
});

it('gives a type without extensions no ext, and a type whose every field is public no visibleTo()', function (): void {
    $contract = RecordContracts::of(SchemaFixtures::schema(['note' => ['title' => 'text']])->types[0]);

    expect(array_keys(propertiesByKey($contract->root)))->toBe(['cms_id', 'title'])
        ->and($contract->root->className)->toBe('AppNoteV1')
        ->and($contract->root->classified())->toBeFalse();

    $files = new PhpRecordDtos()->generate(SchemaFixtures::schema(['note' => ['title' => 'text']]), SchemaFixtures::target());
    $contents = array_map(static fn (GeneratedFile $file): string => $file->contents, $files);

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $files))->toBe([
        'app/Cms/Generated/Domain/Dto/AppNoteV1.php',
        'app/Cms/Generated/Boundary/AppNoteCodecV1.php',
    ])
        ->and($contents[0])->not->toContain('visibleTo')
        ->and($contents[1])->toContain('return JsonText::encode($this->encodeAppNoteV1($dto));')
        ->and($contents[1])->toContain('return $this->decodeAppNoteV1(JsonText::decode($json));');
});

it('refuses two handles of one object that give the same property', function (): void {
    try {
        new PhpRecordDtos()->generate(SchemaFixtures::schema(['note' => ['item_2' => 'text', 'item2' => 'integer']]), SchemaFixtures::target());
    } catch (GenerationFailed $failure) {
        expect($failure->codes())->toBe([GenerateErrorCode::NameCollision])
            ->and($failure->getMessage())->toContain('The fields item2 and item_2 of AppNoteV1 both become the property $item2.');

        return;
    }

    throw new LogicException('The collision was not refused.');
});

it('refuses a group and a type that give the same class', function (): void {
    try {
        new PhpRecordDtos()->generate(SchemaFixtures::schema(['a' => ['b_v1' => 'group'], 'a_v1_b' => ['title' => 'text']]), SchemaFixtures::target());
    } catch (GenerationFailed $failure) {
        expect($failure->codes())->toBe([GenerateErrorCode::NameCollision])
            ->and($failure->getMessage())->toContain('The records AppAV1 and AppAV1BV1 both have a DTO class AppAV1BV1.');

        return;
    }

    throw new LogicException('The collision was not refused.');
});

it('refuses a field type the record has no mapping for', function (): void {
    $type = ComprehensiveExample::compile()->types[0];
    $field = $type->fields[0];
    $unknown = new FieldDescriptor(
        $field->column,
        $field->handle,
        $field->namespace,
        $field->owner,
        'acme:colour',
        $field->label,
        $field->description,
        $field->required,
        $field->classification,
        $field->agents,
        $field->filterable,
        $field->sortable,
        $field->encrypted,
        $field->php,
        $field->typeScript,
        $field->validation,
        $field->choices,
        $field->fields,
        $field->location,
    );
    $changed = new TypeDescriptor($type->typeId, $type->owner, $type->handle, $type->label, $type->description, $type->version, $type->capabilities, $type->extensions, [$unknown], $type->location);

    try {
        RecordContracts::of($changed);
    } catch (GenerationFailed $failure) {
        expect($failure->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failure->getMessage())->toContain('has no mapping for the field type "acme:colour"');

        return;
    }

    throw new LogicException('The field type was not refused.');
});
