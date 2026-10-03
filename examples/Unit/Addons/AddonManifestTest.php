<?php

declare(strict_types=1);

namespace Examples\Unit\Addons;

use Examples\Unit\Addons\Reviews\RequireStars;
use Examples\Unit\Addons\Reviews\ReviewsFieldTypes;
use Examples\Unit\Addons\Reviews\ReviewsServiceProvider;
use Examples\Unit\Addons\Reviews\UnlistedHookServiceProvider;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Build\Notes\NotesServiceProvider;
use Examples\Unit\Build\Notes\PublishNote;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles the reviews addon's manifest with its scan root: the hook the manifest allows
 * is registered under the addon with what it may read, and the field type goes to schema.php. A
 * hook the manifest does not allow stops the build.
 */
final class AddonManifestTest extends BuildTestCase
{
    #[Test]
    public function it_registers_the_addon_s_hook_and_schema_contributions(): void
    {
        $this->allowAddons('acme/cms-reviews');
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        $hooks = require $this->registryFile('hooks');
        self::assertIsArray($hooks);
        self::assertIsArray($hooks['entries']);
        self::assertContains([
            'addon' => 'reviews',
            'budget_ms' => 2,
            'class' => RequireStars::class,
            'command' => 'note.publish',
            'command_class' => PublishNote::class,
            'command_version' => 1,
            'package' => 'acme/cms-reviews',
            'phase' => 'validate',
            'priority' => 30,
            'reads' => 'internal',
        ], $hooks['entries']);

        $schema = require $this->registryFile('schema');
        self::assertIsArray($schema);
        self::assertSame('schema', $schema['registry']);
        self::assertIsArray($schema['entries']);
        self::assertContains([
            'extends' => [],
            'field_type_contributor' => ReviewsFieldTypes::class,
            'field_types' => ['reviews:stars'],
            'namespace' => 'reviews',
            'package' => 'acme/cms-reviews',
            'types' => [],
        ], $schema['entries']);
    }

    #[Test]
    public function it_refuses_a_hook_the_manifest_does_not_allow_and_writes_nothing(): void
    {
        $this->allowAddons('acme/cms-reviews');
        self::assertSame(65, $this->build(NotesServiceProvider::class, UnlistedHookServiceProvider::class));
        self::assertStringContainsString(
            '[registry_undeclared_hook] Hook '.RequireStars::class.' (acme/cms-reviews) runs for '.PublishNote::class.' (note.publish) in the validate phase, which the manifest of addon "reviews" does not allow.',
            $this->buildOutput(),
        );
        self::assertDirectoryDoesNotExist($this->registryDirectory());
    }
}
