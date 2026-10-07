<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Which screenshots the documentation shows, and what each one is for.
 *
 * One list, so a command whose output changes has one place to update, and a shot no page embeds
 * is a finding of `composer docs:check` instead of a stale image nobody notices. Each shot is the
 * real output of its command, run by `composer docs:screenshots` from the repository root and drawn
 * as a terminal window in docs/screenshots/<key>.svg, which is committed. A command whose output
 * depends on the clock, the machine or the services runs in a Scene, through
 * tools/bin/docs-scene.php, and its prompt line shows the command a developer types; so capturing
 * the shots again on any machine and any day gives the same bytes, and ScreenshotsTest fails when
 * a committed image is no longer what its command draws.
 *
 * Adding one:
 *
 *  1. an entry here, with the caption the pages use and the exit code the command must end with;
 *  2. `![<caption>](<relative path>/screenshots/<key>.svg)` on the page that describes it;
 *  3. `composer docs:screenshots -- --only=<key>`.
 *
 * `composer docs:check` then holds the three together: every entry has its image, every image
 * below docs/screenshots has an entry, every entry is embedded by a page outside
 * docs/screenshots, and every embed uses the entry's caption.
 */
final readonly class Screenshots
{
    /**
     * @return list<Screenshot>
     */
    public static function all(): array
    {
        return [
            new Screenshot(
                'doctor',
                [PHP_BINARY, 'tools/bin/docs-scene.php', Scene::Healthy->value, 'cms:doctor'],
                'cms:doctor on a healthy installation. Every runtime check passes, and each line says what the check looked at and what it found.',
                prompt: 'vendor/bin/testbench cms:doctor',
            ),
            new Screenshot(
                'doctor-violation',
                [PHP_BINARY, 'tools/bin/docs-scene.php', Scene::AllowUrlFopen->value, 'cms:doctor'],
                'cms:doctor with allow_url_fopen turned on. The failing check gives the cause, the fix and the error code, and the doctor exits 78.',
                exitCode: 78,
                prompt: 'php -d allow_url_fopen=1 vendor/bin/testbench cms:doctor',
            ),
        ];
    }

    /**
     * The browser screenshots: pages of the panel as a Browser test of gate 8 shows them, captured
     * into docs/screenshots/<key>.png by running the test with CMS_DOCS_SCREENSHOTS=1 in the dev
     * image, after `composer panel:build`. They are not compared again, because a browser draws
     * text with the fonts of its machine; the test asserts what the page shows.
     *
     * @return list<BrowserScreenshot>
     */
    public static function browser(): array
    {
        return [
            new BrowserScreenshot(
                'branding-login',
                'The login page of a branded panel: the installation\'s logo and name above the form, in the light mode.',
                'tests/Browser/Panel/BrandingTest.php',
            ),
            new BrowserScreenshot(
                'branding-shell',
                'The start page of a branded panel: the shell\'s header with the installation\'s logo and name.',
                'tests/Browser/Panel/BrandingTest.php',
            ),
            new BrowserScreenshot(
                'account-me',
                'The who-am-I page: the shell\'s navigation with its entry marked as the current page, and the person\'s profile, actor and the grants they hold, read with actor.me.',
                'tests/Browser/Panel/ShellTest.php',
            ),
            new BrowserScreenshot(
                'palette',
                'The command palette open over the start page on a desktop: the search field, the pages the person may open and the commands they may run, as action.list decided them.',
                'tests/Browser/Panel/PaletteTest.php',
            ),
            new BrowserScreenshot(
                'command-form',
                'The generic command form on a desktop, rendered from the schema of actor.activate: the fields labelled by the catalogue, the run options, and below them the receipt of a dry run with what would change.',
                'tests/Browser/Panel/CommandFormTest.php',
            ),
            new BrowserScreenshot(
                'command-form-mobile',
                'The generic command form on a phone: the same fields and run options filling the width, each field with its label and description.',
                'tests/Browser/Panel/CommandFormTest.php',
            ),
            new BrowserScreenshot(
                'palette-mobile',
                'The command palette open on a phone: the same pages and commands, the search field and the entries filling the width.',
                'tests/Browser/Panel/PaletteTest.php',
            ),
            new BrowserScreenshot(
                'access-roles',
                'The roles page on a desktop: every role with its handle, classification ceiling and permissions, read with role.list, and the button that creates one.',
                'tests/Browser/Panel/RolesAndGrantsTest.php',
            ),
            new BrowserScreenshot(
                'access-roles-mobile',
                'The roles page on a phone: the same roles in a table that scrolls, with the shell\'s navigation folded away.',
                'tests/Browser/Panel/RolesAndGrantsTest.php',
            ),
            new BrowserScreenshot(
                'access-grants',
                'The grants page on a desktop: who holds which role where, each grant with its member of staff, role, node, effect and languages, read with grant.list, and the button that assigns one.',
                'tests/Browser/Panel/RolesAndGrantsTest.php',
            ),
            new BrowserScreenshot(
                'access-grants-mobile',
                'The grants page on a phone: the same grants in a table that scrolls.',
                'tests/Browser/Panel/RolesAndGrantsTest.php',
            ),
            new BrowserScreenshot(
                'access-grant-assign',
                'The form that assigns a grant, open over the grants page: the pickers of the member of staff, the role and the node, the effect and the languages.',
                'tests/Browser/Panel/RolesAndGrantsTest.php',
            ),
        ];
    }

    /**
     * The shot with the key, or null.
     */
    public static function find(string $key): ?Screenshot
    {
        return array_find(self::all(), static fn (Screenshot $shot): bool => $shot->key === $key);
    }
}
