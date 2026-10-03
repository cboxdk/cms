<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ThemeSourcesBehaviour against the fake the action tests use.
 */
final class FakeThemeSourcesBehaviourTest extends TestCase
{
    use ThemeSourcesBehaviour;

    #[Override]
    protected function sources(array $files): ThemeSources
    {
        $byPath = [];

        foreach ($files as $name => $json) {
            $byPath[$this->path($name)] = $json;
        }

        return ThemeWorld::sources($byPath);
    }

    #[Override]
    protected function path(string $name): string
    {
        return '/srv/themes/'.$name;
    }
}
