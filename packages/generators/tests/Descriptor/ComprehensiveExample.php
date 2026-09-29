<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * The comprehensive example type of the type chain (PRD 11.12, MILESTONES M1 point 1): the type
 * product of the module shop in Fixtures/Comprehensive/shop and the app's extension of it in
 * Fixtures/Comprehensive/app, read by the YAML source as cms:generate reads them, and the committed
 * golden file of its descriptor, Fixtures/Comprehensive/descriptor.json.
 */
final class ComprehensiveExample
{
    public const string DIRECTORY = __DIR__.'/Fixtures/Comprehensive';

    public const string GOLDEN = self::DIRECTORY.'/descriptor.json';

    /**
     * The schema roots of the example: the module shop, which owns the type, and the app.
     *
     * @return list<SchemaRoot>
     */
    public static function roots(): array
    {
        return [
            new SchemaRoot(new Owner('app'), self::DIRECTORY, 'app'),
            new SchemaRoot(new Owner('shop'), self::DIRECTORY, 'shop'),
        ];
    }

    /**
     * The YAML source with the core's field types, as cms:generate reads the blueprints.
     */
    public static function source(): YamlBlueprintSource
    {
        return new YamlBlueprintSource(new BlueprintSchemaFile, new BlueprintDocumentReader(new FieldTypeRegistry(new CoreFieldTypes)), new BlueprintRules);
    }

    public static function compile(): CompiledSchema
    {
        return DescriptorCompiler::compile(SchemaResolver::resolve(self::source()->read(self::roots())));
    }
}
