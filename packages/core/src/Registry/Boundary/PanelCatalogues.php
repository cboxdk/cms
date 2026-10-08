<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonCatalogues;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelCatalogue;
use JsonException;

/**
 * Reads an addon's panel catalogues from the directory its PanelContributions name (PRD 13.4,
 * section 2.6 of the panel extension architecture): one file per locale the panel ships,
 * `<locale>.json`, each one JSON object from a key of dot-separated lower-case words to a non-empty
 * text, as the panel's own catalogues are and as `npm run lint:translations` holds them. What is
 * wrong is described in the AddonCatalogues, which the compiler reports as
 * registry_panel_catalogue_invalid, a locale whose file is missing as
 * registry_panel_translations_incomplete; the compiler also holds every key to the addon's
 * namespace, which this reader does not know.
 */
#[Internal]
final readonly class PanelCatalogues
{
    /** The form of a key, the same as the panel's own catalogues' (js/tooling/translation-parity.js). */
    public const string KEY_PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/';

    /** The longest text a catalogue may hold, as contributions.v1.json carries it. */
    public const int MAX_TEXT_LENGTH = 1000;

    public static function read(string $directory): AddonCatalogues
    {
        $catalogues = [];
        $problems = [];

        foreach (PanelLocale::cases() as $locale) {
            $name = $locale->file();
            $json = LocalFiles::read(rtrim($directory, '/').'/'.$name);

            if ($json === null) {
                continue;
            }

            $texts = self::texts($json, $name, $problems);

            if ($texts !== null) {
                $catalogues[] = new PanelCatalogue($locale, $texts);
            }
        }

        foreach (LocalFiles::files($directory, '.json') as $name => $_) {
            if (! array_any(PanelLocale::cases(), static fn (PanelLocale $locale): bool => $locale->file() === $name)) {
                $problems[] = sprintf('%s is no catalogue of the locales the panel ships, %s', $name, implode(' and ', array_map(static fn (PanelLocale $locale): string => $locale->file(), PanelLocale::cases())));
            }
        }

        return new AddonCatalogues($catalogues, $problems);
    }

    /**
     * The texts of one catalogue by key, sorted, or null when the file is not of its form.
     *
     * @param  list<string>  $problems
     * @return array<string, string>|null
     */
    private static function texts(string $json, string $name, array &$problems): ?array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            $problems[] = sprintf('%s is not valid JSON: %s', $name, $invalid->getMessage());

            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $problems[] = sprintf('%s is not one JSON object of keys to texts', $name);

            return null;
        }

        $texts = [];

        foreach ($decoded as $key => $text) {
            $key = (string) $key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $problems[] = sprintf('the key "%s" of %s is not dot-separated lower-case words, digits and underscores', $key, $name);

                continue;
            }

            if (! is_string($text) || trim($text) === '') {
                $problems[] = sprintf('the key "%s" of %s has no text', $key, $name);

                continue;
            }

            if (strlen($text) > self::MAX_TEXT_LENGTH) {
                $problems[] = sprintf('the text of "%s" in %s is %d bytes, and a text is at most %d', $key, $name, strlen($text), self::MAX_TEXT_LENGTH);

                continue;
            }

            $texts[$key] = $text;
        }

        ksort($texts, SORT_STRING);

        return $texts;
    }
}
