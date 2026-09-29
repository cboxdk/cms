<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Blueprints for the tests of the type table migrations: a small type of the app, `note`, whose
 * fields a test changes one at a time, compiled from YAML as cms:generate compiles it.
 */
final class MigrationFixtures
{
    public const string TYPE_ID = '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1';

    public const string TITLE = <<<'YAML'
          - handle: title
            label: Title
            description: The title.
            type: text
            required: true
            classification: public
            filterable: true

        YAML;

    public const string RATING = <<<'YAML'
          - handle: rating
            label: Rating
            description: The rating.
            type: integer
            classification: public
            min: 1
            max: 5

        YAML;

    /**
     * The blueprint of `note` with the fields, each a YAML list item as TITLE and RATING are.
     */
    public static function note(string $fields = self::TITLE.self::RATING, string $stages = 'draft-release', string $typeId = self::TYPE_ID, string $handle = 'note'): string
    {
        return <<<YAML
            blueprint: 1
            kind: type
            type_id: {$typeId}
            handle: {$handle}
            label: Note
            version: 1
            capabilities:
              history: full
              stages: {$stages}
              localization: none
            fields:

            YAML.$fields;
    }

    /**
     * The compiled schema of blueprint files below a scratch schema root of the app: file name to
     * YAML, and extension files below the root of the owner `acme`.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $acme
     */
    public static function compile(array $files, array $acme = []): CompiledSchema
    {
        $base = SchemaFixtures::scratch();
        $roots = [new SchemaRoot(new Owner('app'), $base, 'schema')];

        foreach ($files as $name => $yaml) {
            SchemaFixtures::write($base.'/schema/'.$name, $yaml);
        }

        if ($acme !== []) {
            $roots[] = new SchemaRoot(new Owner('acme'), $base, 'acme');

            foreach ($acme as $name => $yaml) {
                SchemaFixtures::write($base.'/acme/'.$name, $yaml);
            }
        }

        return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read($roots)));
    }

    /**
     * The failure a call ends with.
     *
     * @param  Closure(): mixed  $call
     */
    public static function failure(Closure $call): GenerationFailed
    {
        try {
            $call();
        } catch (GenerationFailed $failed) {
            return $failed;
        }

        Assert::fail('The call did not fail.');
    }

    /**
     * The codes of the failure a call ends with.
     *
     * @param  Closure(): mixed  $call
     * @return list<GenerateErrorCode>
     */
    public static function codes(Closure $call): array
    {
        return self::failure($call)->codes();
    }
}
