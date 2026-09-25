<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpToken;

/**
 * Finds every phpstan-ignore annotation, of any form, in PHP source with the tokenizer.
 *
 * The tokenizer sees every comment, also one that PHPStan attaches to no node, such as a
 * comment at the end of a file or inside an empty block. Each annotation gets the line it is
 * on, also inside a multi-line comment, and the namespace it is in.
 */
#[Internal]
final class IgnoreCommentScanner
{
    private const string PATTERN = '/@phpstan-ignore[A-Za-z-]*/';

    /**
     * @return list<IgnoreComment>
     */
    public static function scan(string $code): array
    {
        $tokens = array_values(PhpToken::tokenize($code));
        $namespace = '';
        $found = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = self::declaredNamespace($tokens, $index) ?? $namespace;

                continue;
            }

            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if (preg_match_all(self::PATTERN, $token->text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$tag, $offset]) {
                $found[] = new IgnoreComment($tag, $token->line + substr_count($token->text, "\n", 0, $offset), $namespace);
            }
        }

        return $found;
    }

    /**
     * The name after a namespace keyword; '' for a braced global namespace; null when the
     * keyword is not a namespace declaration, as in namespace\foo().
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function declaredNamespace(array $tokens, int $index): ?string
    {
        foreach (array_slice($tokens, $index + 1) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->text === '{') {
                return '';
            }

            return $token->is([T_STRING, T_NAME_QUALIFIED]) ? $token->text : null;
        }

        return null;
    }
}
