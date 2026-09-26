<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use RuntimeException;

/**
 * Reads the scripts of the monorepo's composer.json, so the tests can check what a Composer script
 * runs, step by step, as Composer runs it.
 */
final readonly class ComposerScripts
{
    /**
     * The commands of a script in order, with @script references expanded. Composer runs them one
     * after the other and stops at the first that fails, with its exit code.
     *
     * @return list<string>
     */
    public static function steps(string $name): array
    {
        $scripts = self::scripts();
        $script = $scripts[$name] ?? throw new RuntimeException("composer.json has no script {$name}.");
        $steps = [];

        foreach (is_array($script) ? $script : [$script] as $step) {
            if (! is_string($step)) {
                throw new RuntimeException("Script {$name} has a step that is not a string.");
            }

            $reference = str_starts_with($step, '@') && ! str_starts_with($step, '@php ') ? substr($step, 1) : null;

            array_push($steps, ...($reference !== null && array_key_exists($reference, $scripts) ? self::steps($reference) : [$step]));
        }

        return $steps;
    }

    /**
     * The description of a script in scripts-descriptions, or null.
     */
    public static function description(string $name): ?string
    {
        $descriptions = self::composer()['scripts-descriptions'] ?? null;
        $description = is_array($descriptions) ? ($descriptions[$name] ?? null) : null;

        return is_string($description) ? $description : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scripts(): array
    {
        $scripts = self::composer()['scripts'] ?? null;

        if (! is_array($scripts)) {
            throw new RuntimeException('composer.json has no scripts.');
        }

        $named = [];

        foreach ($scripts as $name => $script) {
            $named[(string) $name] = $script;
        }

        return $named;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function composer(): array
    {
        $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($composer)) {
            throw new RuntimeException('composer.json is not a JSON object.');
        }

        return $composer;
    }
}
