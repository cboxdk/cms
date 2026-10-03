<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;
use LogicException;

/**
 * The value of every token in a mode, with the values a theme sets over the catalogue's and every
 * reference to another token, `{name}`, replaced by that token's value, as js/ui-kit/scripts/tokens.js
 * resolves the catalogue. A token that refers to a token a theme sets therefore follows it: a theme
 * that sets color-accent changes color-focus, which refers to it, too.
 */
#[Experimental]
final readonly class ResolvedTokens
{
    private const string REFERENCE = '/\{([a-z0-9-]+)\}/';

    /** References deeper than this are a cycle, which the catalogue's own check refuses. */
    private const int MAX_DEPTH = 32;

    /**
     * @param  array<string, TokenValue>  $values  the literal values set over the catalogue, by token name
     * @return array<string, string> by token name, in the catalogue's order
     */
    public static function of(TokenCatalogue $catalogue, array $values, Mode $mode): array
    {
        $resolved = [];

        foreach ($catalogue->tokens as $token) {
            $resolved[$token->name] = self::resolve($catalogue, $values, $token->name, $mode, 0);
        }

        return $resolved;
    }

    /**
     * @param  array<string, TokenValue>  $values
     */
    private static function resolve(TokenCatalogue $catalogue, array $values, string $name, Mode $mode, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new LogicException(sprintf('The token catalogue refers to itself through %s. Run npm run test:kit -- tokens.', $name));
        }

        $written = ($values[$name] ?? $catalogue->token($name)?->value)?->in($mode);

        if ($written === null) {
            throw new LogicException(sprintf('The token catalogue refers to the unknown token %s. Run npm run test:kit -- tokens.', $name));
        }

        $resolved = $written;

        while (preg_match(self::REFERENCE, $resolved, $match, PREG_OFFSET_CAPTURE) === 1) {
            $replacement = self::resolve($catalogue, $values, $match[1][0], $mode, $depth + 1);
            $resolved = substr_replace($resolved, $replacement, $match[0][1], strlen($match[0][0]));
        }

        return $resolved;
    }
}
