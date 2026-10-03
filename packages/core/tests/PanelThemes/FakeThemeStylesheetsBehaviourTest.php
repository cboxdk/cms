<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeStylesheets;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ThemeStylesheetsBehaviour against the fake the action tests use.
 */
final class FakeThemeStylesheetsBehaviourTest extends TestCase
{
    use ThemeStylesheetsBehaviour;

    private ?FakeThemeStylesheets $fake = null;

    #[Override]
    protected function stylesheets(): ThemeStylesheets
    {
        return $this->fake();
    }

    #[Override]
    protected function refuseWrites(): void
    {
        $this->fake()->refuseWrites();
    }

    private function fake(): FakeThemeStylesheets
    {
        return $this->fake ??= new FakeThemeStylesheets;
    }
}
