<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A string, quoted as Prettier quotes it: in single quotes, or in double quotes when that needs
 * fewer escapes.
 */
#[Internal]
final readonly class StringLiteral implements Literal
{
    public function __construct(public string $value) {}

    public function flat(): string
    {
        $quote = substr_count($this->value, "'") > substr_count($this->value, '"') ? '"' : "'";

        return $quote.strtr($this->value, [
            '\\' => '\\\\',
            $quote => '\\'.$quote,
            "\n" => '\n',
            "\r" => '\r',
            "\t" => '\t',
        ]).$quote;
    }
}
