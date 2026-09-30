<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Addons;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\AllowedSubscription;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Tests\Fixtures\ReleaseVariant;
use Cbox\Cms\Contracts\Tests\Fixtures\VariantReleased;

/*
 * The addon manifest (PRD 13.1, 11.12): the namespace rules, the core API version, and what the
 * manifest holds to the addon's namespace.
 */

/**
 * @param  list<AllowedHook>  $hooks
 * @param  list<AllowedSubscription>  $subscriptions
 */
function addonManifest(SchemaContributions $schema = new SchemaContributions, array $hooks = [], array $subscriptions = []): AddonManifest
{
    return new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), new CoreApiVersion(1, 0), '/srv/reviews/docs', new AddonCapabilities, $hooks, $subscriptions, $schema);
}

it('takes a namespace of a lowercase letter and at most 19 lowercase letters and digits', function (string $namespace): void {
    expect(new AddonNamespace($namespace)->value)->toBe($namespace)
        ->and(new AddonNamespace($namespace)->fieldNamespace()->value)->toBe($namespace);
})->with(['a', 'acme', 'shop2', 'a'.str_repeat('b', 19)]);

it('refuses a namespace that is not one', function (string $namespace): void {
    expect(static fn (): AddonNamespace => new AddonNamespace($namespace))->toThrow(InvalidAddonManifest::class, sprintf('The addon namespace "%s" is not a lowercase letter', $namespace));
})->with(['', 'Acme', 'acme_reviews', '2acme', 'acme-reviews', 'a'.str_repeat('b', 20), "acme\n"]);

it('refuses the reserved namespaces app and ext with their own exception', function (string $namespace): void {
    expect(static fn (): AddonNamespace => new AddonNamespace($namespace))->toThrow(ReservedAddonNamespace::class, sprintf('The addon namespace "%s" is reserved', $namespace));
})->with(AddonNamespace::RESERVED);

it('compares namespaces by value', function (): void {
    expect(new AddonNamespace('acme')->equals(new AddonNamespace('acme')))->toBeTrue()
        ->and(new AddonNamespace('acme')->equals(new AddonNamespace('shop')))->toBeFalse();
});

it('satisfies a core API version with the same major version and at least its minor version', function (int $major, int $minor, bool $satisfied): void {
    expect(new CoreApiVersion($major, $minor)->satisfiedBy(new CoreApiVersion(1, 4)))->toBe($satisfied);
})->with([
    'the same version' => [1, 4, true],
    'an earlier minor version' => [1, 0, true],
    'a later minor version' => [1, 5, false],
    'an earlier major version' => [0, 4, false],
    'a later major version' => [2, 0, false],
]);

it('writes a core API version and the constraint it means, and knows the kernel\'s own', function (): void {
    expect(new CoreApiVersion(1, 4)->toString())->toBe('1.4')
        ->and(new CoreApiVersion(1, 4)->constraint())->toBe('^1.4')
        ->and(CoreApiVersion::current())->toEqual(new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR))
        ->and(CoreApiVersion::current()->satisfiedBy(CoreApiVersion::current()))->toBeTrue()
        ->and(static fn (): CoreApiVersion => new CoreApiVersion(1, -1))->toThrow(InvalidAddonManifest::class, 'The core API version 1.-1 has a part below 0.');
});

it('reads a field type as its namespace and handle, and refuses a name that is not one', function (): void {
    $stars = new ContributedFieldType('reviews:star_rating');

    expect($stars->namespace)->toBe('reviews')
        ->and($stars->handle)->toBe('star_rating')
        ->and($stars->equals(new ContributedFieldType('reviews:star_rating')))->toBeTrue()
        ->and(static fn (): ContributedFieldType => new ContributedFieldType('stars'))->toThrow(InvalidAddonManifest::class, 'The field type "stars" is not <namespace>:<handle>')
        ->and(static fn (): ContributedFieldType => new ContributedFieldType('reviews:star__rating'))->toThrow(InvalidAddonManifest::class)
        ->and(static fn (): ContributedFieldType => new ContributedFieldType('reviews:'.str_repeat('a', 64)))->toThrow(InvalidAddonManifest::class);
});

it('holds a manifest with what it allows, its capabilities and its schema contributions sorted', function (): void {
    $manifest = new AddonManifest(
        'acme/cms-reviews',
        new AddonNamespace('reviews'),
        new CoreApiVersion(1, 0),
        '/srv/reviews/docs',
        new AddonCapabilities(ClassificationAccess::Internal),
        [new AllowedHook('\\'.ReleaseVariant::class, Phase::Validate)],
        [new AllowedSubscription(VariantReleased::class, Lane::Standard)],
        new SchemaContributions(
            [new ContributedFieldType('reviews:stars'), new ContributedFieldType('reviews:rating')],
            [new TypeName('reviews:review')],
            [new TypeName('shop:product'), new TypeName('app:note')],
            '/srv/reviews/schema',
            '\\Acme\\Reviews\\ReviewsFieldTypes',
        ),
    );

    expect($manifest->capabilities->reads)->toBe(ClassificationAccess::Internal)
        ->and($manifest->hooks[0]->command)->toBe(ReleaseVariant::class)
        ->and($manifest->allowsHook(strtolower(ReleaseVariant::class), Phase::Validate))->toBeTrue()
        ->and($manifest->allowsHook(ReleaseVariant::class, Phase::Transform))->toBeFalse()
        ->and($manifest->allowsSubscription('\\'.VariantReleased::class, Lane::Standard))->toBeTrue()
        ->and($manifest->allowsSubscription(VariantReleased::class, Lane::Critical))->toBeFalse()
        ->and(array_map(static fn (ContributedFieldType $type): string => $type->value, $manifest->schema->fieldTypes))->toBe(['reviews:rating', 'reviews:stars'])
        ->and(array_map(static fn (TypeName $type): string => $type->value, $manifest->schema->extends))->toBe(['app:note', 'shop:product'])
        ->and($manifest->schema->fieldTypeContributor)->toBe('Acme\\Reviews\\ReviewsFieldTypes')
        ->and($manifest->schema->isEmpty())->toBeFalse()
        ->and(new SchemaContributions()->isEmpty())->toBeTrue()
        ->and(new AddonCapabilities()->reads)->toBe(ClassificationAccess::Public);
});

it('refuses a manifest that breaks the rules of its namespace or lists something twice', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidAddonManifest::class, $message);
})->with([
    'a package that is not a Composer name' => [static fn (): AddonManifest => new AddonManifest('reviews', new AddonNamespace('reviews'), new CoreApiVersion(1, 0), '/srv/docs'), 'The addon package "reviews" is not a Composer package name'],
    'a documentation directory that is not absolute' => [static fn (): AddonManifest => new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), new CoreApiVersion(1, 0), 'docs'), 'The documentation directory "docs" is not an absolute path.'],
    'a field type of another namespace' => [static fn (): AddonManifest => addonManifest(new SchemaContributions([new ContributedFieldType('shop:stars')], fieldTypeContributor: 'Acme\\Shop\\ShopFieldTypes')), 'The addon "reviews" contributes the field type "shop:stars", which is outside its namespace.'],
    'an own type of another owner' => [static fn (): AddonManifest => addonManifest(new SchemaContributions(types: [new TypeName('app:review')], directory: '/srv/schema')), 'The addon "reviews" owns the type "app:review", which is outside its namespace.'],
    'an extension of its own type' => [static fn (): AddonManifest => addonManifest(new SchemaContributions(extends: [new TypeName('reviews:review')], directory: '/srv/schema')), 'The addon "reviews" extends its own type "reviews:review".'],
    'a hook allowed twice' => [static fn (): AddonManifest => addonManifest(hooks: [new AllowedHook(ReleaseVariant::class, Phase::Validate), new AllowedHook(strtolower(ReleaseVariant::class), Phase::Validate)]), sprintf('The hook %s in the validate phase is listed twice.', strtolower(ReleaseVariant::class))],
    'a subscription allowed twice' => [static fn (): AddonManifest => addonManifest(subscriptions: [new AllowedSubscription(VariantReleased::class, Lane::Standard), new AllowedSubscription(VariantReleased::class, Lane::Standard)]), sprintf('The subscription %s on the standard lane is listed twice.', VariantReleased::class)],
    'a field type listed twice' => [static fn (): SchemaContributions => new SchemaContributions([new ContributedFieldType('reviews:stars'), new ContributedFieldType('reviews:stars')], fieldTypeContributor: 'Acme\\Reviews\\ReviewsFieldTypes'), 'The field type "reviews:stars" is listed twice.'],
    'field types without their contributor' => [static fn (): SchemaContributions => new SchemaContributions([new ContributedFieldType('reviews:stars')]), 'The addon contributes field types but names no field type contributor.'],
    'a contributor without field types' => [static fn (): SchemaContributions => new SchemaContributions(fieldTypeContributor: 'Acme\\Reviews\\ReviewsFieldTypes'), 'The addon names a field type contributor but contributes no field type.'],
    'a contributor that is not a class name' => [static fn (): SchemaContributions => new SchemaContributions([new ContributedFieldType('reviews:stars')], fieldTypeContributor: 'reviews field types'), 'The class name "reviews field types" of the field type contributor is not a PHP class name.'],
    'types without a schema directory' => [static fn (): SchemaContributions => new SchemaContributions(types: [new TypeName('reviews:review')]), 'The addon owns or extends types but names no schema directory.'],
    'a schema directory that is not absolute' => [static fn (): SchemaContributions => new SchemaContributions(directory: 'schema'), 'The schema directory "schema" is not an absolute path.'],
    'a hook whose command is not a class name' => [static fn (): AllowedHook => new AllowedHook('release variant', Phase::Validate), 'The class name "release variant" of an allowed hook\'s command is not a PHP class name.'],
    'a subscription whose event is not a class name' => [static fn (): AllowedSubscription => new AllowedSubscription('', Lane::Standard), 'The class name "" of an allowed subscription\'s event is not a PHP class name.'],
]);
