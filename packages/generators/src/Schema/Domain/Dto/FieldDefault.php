<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\DefaultToken;

/**
 * The `default` of a field in its blueprint (gap G2): the value a form starts the input with. It
 * is form only, never filled in by a command, and the blueprint schema takes a string, an
 * integer, a boolean or a list of strings; BlueprintRules holds it to the kind the field's type
 * takes (generate_default_wrong_kind), and reads `today` on a `date` field and `now` on a
 * `datetime` field as the token the form resolves from the clock.
 *
 * One of the three members is set: the token, the constant, or the values of a select with
 * `multiple: true`.
 */
#[Internal]
final readonly class FieldDefault
{
    /**
     * @param  ?list<string>  $values
     */
    private function __construct(
        public ?DefaultToken $token,
        public string|int|bool|null $constant,
        public ?array $values,
    ) {}

    public static function token(DefaultToken $token): self
    {
        return new self($token, null, null);
    }

    public static function constant(string|int|bool $value): self
    {
        return new self(null, $value, null);
    }

    /**
     * @param  list<string>  $values
     */
    public static function values(array $values): self
    {
        return new self(null, null, $values);
    }

    /**
     * The default as a problem names it: `a string`, `an integer`, `a boolean`, `a list of
     * strings` or `the token today`.
     */
    public function describe(): string
    {
        return match (true) {
            $this->token instanceof DefaultToken => 'the token '.$this->token->value,
            is_string($this->constant) => 'a string',
            is_int($this->constant) => 'an integer',
            is_bool($this->constant) => 'a boolean',
            default => 'a list of strings',
        };
    }
}
