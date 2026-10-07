<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Ids\TypeId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireWellFormedSlug;

/**
 * The fixture addon's validate hook on entry.create: a slug set by hand in
 * ext.fixtureaddon.fixture_slug of a revision of app:fixture_article must be well formed, and
 * the hook is held to the verdicts of resources/panel/parity/slug-shape.json, which the panel's
 * check fixtureaddon.slug-shape mirrors (the mirror rule, PRD 13.4), case by case.
 */
final class RequireWellFormedSlugTest extends TestCase
{
    private const string PARITY = __DIR__.'/../../resources/panel/parity/slug-shape.json';

    #[Test]
    public function it_refuses_a_slug_that_is_not_well_formed_at_the_addons_field(): void
    {
        $views = new PlanViews;

        $errors = new RequireWellFormedSlug()->validate($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'A quiet Week')),
        ))->errors;

        self::assertEquals([HookError::onField(
            FixtureArticle::slug(),
            'The slug "A quiet Week" is not well formed: use lowercase letters and digits joined by single hyphens, such as a-quiet-week.',
            FixtureArticle::namespace(),
        )], $errors);
    }

    #[Test]
    public function it_passes_a_well_formed_slug_a_revision_without_one_and_other_types(): void
    {
        $views = new PlanViews;
        $hook = new RequireWellFormedSlug;

        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'a-quiet-week'))))->isEmpty());
        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week'))))->isEmpty());
        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), $views->otherType(), PlanViews::fields('A quiet week', 'Not This Type'))))->isEmpty());
    }

    #[Test]
    public function it_gives_the_verdicts_the_panels_mirrored_check_is_held_to(): void
    {
        $cases = $this->cases();
        $views = new PlanViews;
        $hook = new RequireWellFormedSlug;

        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            $document = $case['document'];
            $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
            $title = $fields['fixture_title'] ?? null;
            $extension = is_array($fields['ext'] ?? null) && is_array($fields['ext']['fixtureaddon'] ?? null) ? $fields['ext']['fixtureaddon'] : [];
            $slug = $extension['fixture_slug'] ?? null;
            $own = is_string($title) ? new FieldMap(new NamedValue(FixtureArticle::title(), new TextValue($title))) : new FieldMap;
            $values = is_string($slug)
                ? new FieldValues($own, new ExtensionFields(FixtureArticle::namespace(), new FieldMap(new NamedValue(FixtureArticle::slug(), new TextValue($slug)))))
                : new FieldValues($own);
            $type = is_string($document['type'] ?? null) ? TypeId::fromString($document['type']) : PlanViews::article();

            $refused = array_map(
                static fn (HookError $error): string => 'fields.ext.'.($error->namespace instanceof FieldNamespace ? $error->namespace->value : '').'.'.($error->handle instanceof FieldHandle ? $error->handle->value : ''),
                $hook->validate($views->create($views->revision($views->entry(), $type, $values)))->errors,
            );

            self::assertSame($case['refused'], $refused, $case['name']);
        }
    }

    /**
     * @return list<array{name: string, document: array<string, mixed>, refused: list<string>}>
     */
    private function cases(): array
    {
        $decoded = json_decode((string) file_get_contents(self::PARITY), true, 16, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach (is_array($decoded) && is_array($decoded['cases'] ?? null) ? $decoded['cases'] : [] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name']);
            self::assertIsArray($case['document']);
            self::assertIsArray($case['refused']);

            $refused = [];

            foreach ($case['refused'] as $path) {
                self::assertIsString($path);
                $refused[] = $path;
            }

            $document = [];

            foreach ($case['document'] as $key => $value) {
                $document[(string) $key] = $value;
            }

            $cases[] = ['name' => $case['name'], 'document' => $document, 'refused' => $refused];
        }

        return $cases;
    }
}
