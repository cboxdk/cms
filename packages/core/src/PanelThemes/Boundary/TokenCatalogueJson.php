<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\PanelThemes\Domain\ContrastKind;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\CatalogueToken;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ContrastPair;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;
use Cbox\Cms\Core\PanelThemes\Domain\Tier;
use Cbox\Cms\Core\PanelThemes\Domain\TokenCatalogueUnavailable;
use Cbox\Cms\Core\PanelThemes\Domain\ValueType;
use stdClass;

/**
 * Reads the kit's token catalogue, js/ui-kit/tokens.json, the single source of the design tokens
 * (PRD 13.4): each token's tier, type and value, one for both modes or one per mode, the contrast
 * pairs and the names of the part hooks. js/ui-kit/scripts/tokens.js checks the catalogue in full
 * before it generates anything from it, and gate 5 runs that check; this reads what the panel's
 * themes need and refuses anything else of another form.
 */
#[Internal]
final readonly class TokenCatalogueJson
{
    /**
     * @throws TokenCatalogueUnavailable
     */
    public static function decode(string $json): TokenCatalogue
    {
        try {
            $document = JsonText::decode($json);
        } catch (DecodingFailed $failed) {
            throw new TokenCatalogueUnavailable('The token catalogue is not JSON: '.$failed->getMessage(), 0, $failed);
        }

        $tokens = [];

        foreach (get_object_vars(self::object($document->tokens ?? null, 'tokens')) as $name => $entry) {
            $name = (string) $name;
            $entry = self::object($entry, 'tokens.'.$name);
            $tier = Tier::tryFrom(self::text($entry->tier ?? null, 'tokens.'.$name.'.tier'));
            $type = ValueType::tryFrom(self::text($entry->type ?? null, 'tokens.'.$name.'.type'));

            if ($tier === null || $type === null) {
                throw new TokenCatalogueUnavailable(sprintf('The token catalogue gives tokens.%s a tier or type it does not know.', $name));
            }

            $value = isset($entry->value)
                ? TokenValue::both(self::text($entry->value, 'tokens.'.$name.'.value'))
                : new TokenValue(self::text($entry->light ?? null, 'tokens.'.$name.'.light'), self::text($entry->dark ?? null, 'tokens.'.$name.'.dark'));
            $tokens[] = new CatalogueToken($name, $tier, $type, $value);
        }

        $contrast = [];
        $pairs = $document->contrast ?? null;

        if (! is_array($pairs)) {
            throw new TokenCatalogueUnavailable('The token catalogue has no list of contrast pairs.');
        }

        foreach ($pairs as $index => $pair) {
            $at = sprintf('contrast[%d]', $index);
            $pair = self::object($pair, $at);
            $kind = ContrastKind::tryFrom(self::text($pair->kind ?? null, $at.'.kind'));

            if ($kind === null) {
                throw new TokenCatalogueUnavailable(sprintf('The token catalogue gives %s a kind other than text or ui.', $at));
            }

            $contrast[] = new ContrastPair(self::text($pair->foreground ?? null, $at.'.foreground'), self::text($pair->background ?? null, $at.'.background'), $kind);
        }

        $parts = array_map(strval(...), array_keys(get_object_vars(self::object($document->parts ?? null, 'parts'))));
        sort($parts, SORT_STRING);

        return new TokenCatalogue($tokens, $contrast, $parts);
    }

    /**
     * @throws TokenCatalogueUnavailable
     */
    private static function object(mixed $value, string $at): stdClass
    {
        return $value instanceof stdClass ? $value : throw new TokenCatalogueUnavailable(sprintf('The token catalogue has no object at %s.', $at));
    }

    /**
     * @throws TokenCatalogueUnavailable
     */
    private static function text(mixed $value, string $at): string
    {
        return is_string($value) ? $value : throw new TokenCatalogueUnavailable(sprintf('The token catalogue has no text at %s.', $at));
    }
}
