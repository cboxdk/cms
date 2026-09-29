<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Validators;

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\Validators\ShopProductValidator;

/*
 * The runtime validators cms:generate writes (PRD 11.8, 11.12, MILESTONES M1 point 2): the
 * comprehensive example's validator equals its committed golden file, its extension fields are
 * validated in their namespace, a required extension field is required only on release (PRD 11.12
 * point 1, invariant 36), and every rule of the descriptor has its rule in the kernel's
 * vocabulary.
 */

/**
 * @return list<string>
 */
function reported(ValidationReport $report): array
{
    return array_map(static fn (CatalogError $error): string => ($error->path?->toString() ?? '(input)').': '.$error->code->value, $report->errors);
}

/**
 * Input for shop:product with its required fields and the app's.
 *
 * @return array<string, mixed>
 */
function validProduct(): array
{
    return ['name' => 'Chair', 'price' => '49.95', 'colour' => 'red', 'ext' => ['app' => ['tax_code' => 'DK-25']]];
}

it('writes the comprehensive example\'s validator as the committed golden file', function (): void {
    $files = ComprehensiveValidator::generate();

    expect($files)->toHaveCount(1)
        ->and($files[0]->path)->toBe(ComprehensiveValidator::GOLDEN)
        ->and($files[0]->contents)->toBe(file_get_contents(ComprehensiveExample::DIRECTORY.'/'.ComprehensiveValidator::GOLDEN))
        ->and(ShopProductValidator::class)->toBe(ComprehensiveValidator::GOLDEN_CLASS);
});

it('writes one validator per type below the Validators directory of the PHP directory', function (): void {
    $generator = new PhpTypeValidators;
    $target = ComprehensiveValidator::target();

    expect($generator->directory($target))->toBe('Generated')
        ->and(PhpTypeValidators::className(ComprehensiveExample::compile()->types[0]))->toBe('ShopProductValidator')
        ->and(new ShopProductValidator)->toBeInstanceOf(TypeValidator::class)
        ->and(new ShopProductValidator()->type()->toString())->toBe('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20');
});

it('validates the extension fields in their namespace, apart from the owner\'s field of the same handle', function (): void {
    $validator = new InputValidator;
    $rules = new ShopProductValidator()->rules();
    $long = str_repeat('x', 121);

    expect(reported($validator->validate($rules, validProduct())))->toBe([])
        ->and(reported($validator->validate($rules, [...validProduct(), 'name' => $long])))->toBe(['name: validation_too_long'])
        ->and(reported($validator->validate($rules, [...validProduct(), 'ext' => ['app' => ['tax_code' => 'DK-25', 'name' => $long]]])))->toBe([])
        ->and(reported($validator->validate($rules, [...validProduct(), 'ext' => ['app' => ['tax_code' => str_repeat('x', 21)]]])))->toBe(['ext.app.tax_code: validation_too_long'])
        ->and(reported($validator->validate($rules, [...validProduct(), 'ext' => ['app' => ['tax_code' => 'DK', 'size' => 1]]])))->toBe(['ext.app.size: validation_unknown_field'])
        ->and(reported($validator->validate($rules, [...validProduct(), 'tax_code' => 'DK'])))->toBe(['tax_code: validation_unknown_field']);
});

it('requires the owner\'s required fields on every write, and a required extension field only on release', function (): void {
    $validator = new InputValidator;
    $rules = new ShopProductValidator()->rules();
    $withoutExtension = ['name' => 'Chair', 'price' => '49.95', 'colour' => 'red'];

    expect(reported($validator->validate($rules, $withoutExtension)))->toBe([])
        ->and(reported($validator->validate($rules, $withoutExtension, ValidationStage::Release)))->toBe(['ext.app.tax_code: validation_required'])
        ->and(reported($validator->validate($rules, ['ext' => ['app' => ['tax_code' => 'DK']]])))->toBe(['colour: validation_required', 'name: validation_required', 'price: validation_required']);
});

it('validates the example\'s groups and lists', function (): void {
    $validator = new InputValidator;
    $rules = new ShopProductValidator()->rules();
    $input = [
        ...validProduct(),
        'dimensions' => [['size' => 'small', 'width' => 0], ['size' => 'huge']],
        'supplier' => ['company' => 'Acme', 'website' => 'mailto:a@example.com'],
        'tags' => ['sale', 'sale'],
    ];

    expect(reported($validator->validate($rules, $input)))->toBe([
        'dimensions[0].width: validation_below_minimum',
        'dimensions[1].size: validation_not_an_option',
        'supplier.website: validation_invalid_format',
        'tags[1]: validation_duplicate_item',
    ]);
});

it('maps every rule of the descriptor to the rule of the same name, except required and nullable', function (ValidationRuleName $name): void {
    $mapped = PhpTypeValidators::ruleName($name);

    if (in_array($name, [ValidationRuleName::Required, ValidationRuleName::Nullable], true)) {
        expect($mapped)->toBeNull();
    } else {
        expect($mapped?->value)->toBe($name->value);
    }
})->with(ValidationRuleName::cases());

it('reaches every rule of the kernel\'s vocabulary from the descriptor\'s', function (): void {
    $reached = array_map(PhpTypeValidators::ruleName(...), ValidationRuleName::cases());

    expect(array_values(array_filter(RuleName::cases(), static fn (RuleName $name): bool => ! in_array($name, $reached, true))))->toBe([])
        ->and(array_map(static fn (Presence $presence): string => $presence->value, Presence::cases()))->toBe(['required', 'required_on_release', 'optional']);
});
