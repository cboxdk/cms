<?php

declare(strict_types=1);

namespace Examples\Unit\Addons;

use Examples\Unit\Addons\Reviews\ReviewsFieldTypes;
use Examples\Unit\Addons\Reviews\ReviewsServiceProvider;
use Examples\Unit\Addons\Reviews\StarsFieldType;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Build\Notes\NotesServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reviews addon's field type reviews:stars in the application: cms:build writes the manifest's
 * contributor, ReviewsFieldTypes, to schema.php, and cms:generate reads a field of the type with
 * StarsFieldType, checks its options against stars.options.json, and writes it in every generator
 * as an integer from 1 to its max, under the name reviews:stars. A field type of a namespace no
 * installed addon has is refused.
 */
final class StarsFieldTypeTest extends BuildTestCase
{
    private string $application = '';

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->application);

        parent::tearDown();
    }

    #[Test]
    public function it_generates_a_field_of_the_addon_s_field_type(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        $schema = require $this->registryFile('schema');
        self::assertIsArray($schema);
        self::assertIsArray($schema['entries']);

        // One entry per addon of the installation, sorted by namespace; this is the reviews addon's.
        $reviews = null;

        foreach ($schema['entries'] as $entry) {
            if (is_array($entry) && ($entry['namespace'] ?? null) === 'reviews') {
                $reviews = $entry;
            }
        }

        self::assertIsArray($reviews);
        self::assertSame(ReviewsFieldTypes::class, $reviews['field_type_contributor']);
        self::assertSame([new StarsFieldType()->name()->value], $reviews['field_types']);

        self::assertSame(0, $this->generate(<<<'YAML'
              - handle: rating
                label: Rating
                description: The stars of the review.
                type: reviews:stars
                classification: public
                sortable: true
                options:
                  max: 7
            YAML));

        self::assertStringContainsString("'rating' => 'reviews:stars',", $this->generated('app/Cms/Generated/TypeHandle.php'));
        self::assertStringContainsString("fieldType: 'reviews:stars',", $this->generated('app/Cms/Generated/GeneratedTypeCatalog.php'));
        self::assertStringContainsString('base: FieldBase::Integer,', $this->generated('app/Cms/Generated/GeneratedTypeCatalog.php'));
        self::assertStringContainsString('public ?int $rating { get; }', $this->generated('app/Cms/Generated/Records/AppReview/AppReviewRecord.php'));
        self::assertStringContainsString("rating: 'reviews:stars';", $this->generated('resources/js/cms/generated/index.ts'));
        self::assertStringContainsString('check ("rating" <= 7)', $this->generated('database/migrations/cms/app__review_0001_create.php'));
    }

    #[Test]
    public function it_checks_the_options_against_the_options_schema_and_refuses_an_unknown_namespace(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        self::assertSame(65, $this->generate(<<<'YAML'
              - handle: rating
                label: Rating
                description: The stars of the review.
                type: reviews:stars
                classification: public
                options:
                  max: 11
              - handle: colour
                label: Colour
                description: A colour of an addon that is not installed.
                type: paints:colour
                classification: public
            YAML));

        $output = app(Kernel::class)->output();
        self::assertStringContainsString('[generate_schema_invalid] schema/review.yaml, /fields/0/options/max: Number must be lower than or equal to 10', $output);
        self::assertStringContainsString('(the options schema of the field type reviews:stars)', $output);
        self::assertStringContainsString('[generate_unknown_field_type] schema/review.yaml, /fields/1/type: no field type contributor registers the field type paints:colour', $output);
    }

    /**
     * Runs cms:generate in a fresh application directory whose schema holds the type review with
     * the fields, and returns its exit code.
     */
    private function generate(string $fields): int
    {
        $this->application = sys_get_temp_dir().'/cms-stars-example-'.bin2hex(random_bytes(8));
        mkdir($this->application.'/schema', 0o700, true);
        file_put_contents($this->application.'/schema/review.yaml', <<<YAML
            blueprint: 1
            kind: type
            type_id: 0192a3b4-c5d6-7e8f-9a0b-0000000000a1
            handle: review
            label: Review
            description: A review rated with the reviews addon's stars.
            version: 1
            capabilities:
              history: full
              stages: none
              localization: none
            fields:
            {$fields}
            YAML);

        config()->set('cbox-cms.generators', [
            'root' => $this->application,
            'roots' => ['app' => 'schema'],
            'php_directory' => 'app/Cms/Generated',
            'php_namespace' => 'App\Cms\Generated',
            'typescript_directory' => 'resources/js/cms/generated',
            'migrations_directory' => 'database/migrations/cms',
        ]);

        return app(Kernel::class)->call('cms:generate');
    }

    private function generated(string $path): string
    {
        $contents = file_get_contents($this->application.'/'.$path);
        self::assertIsString($contents, $path.' was not generated.');

        return $contents;
    }
}
