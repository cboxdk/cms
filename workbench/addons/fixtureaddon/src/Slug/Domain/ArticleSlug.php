<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Domain;

use InvalidArgumentException;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\RequireWellFormedSlug;

/**
 * A well-formed slug of an article, the fixture addon's own value class (PRD 13.4): runs of
 * lowercase letters and digits joined by single hyphens, as RequireWellFormedSlug requires one
 * set by hand, of at most DeriveSlug::MAX_LENGTH characters, the blueprint's rule. The command
 * fixtureaddon.slug.set binds its member `slug` to it, so the generic command form keys the input
 * of that member by this class, and the addon's replacement fixtureaddon.slug-input takes the
 * input's place: a replacement at command.form.field@1 is owned through the value class, and this
 * is the one class the addon owns there.
 */
final readonly class ArticleSlug
{
    /**
     * @throws InvalidArgumentException when the text is not a well-formed slug
     */
    public function __construct(public string $value)
    {
        if (mb_strlen($value, 'UTF-8') > DeriveSlug::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('A slug has at most %d characters; "%s" has %d.', DeriveSlug::MAX_LENGTH, $value, mb_strlen($value, 'UTF-8')));
        }

        if (! RequireWellFormedSlug::isWellFormed($value)) {
            throw new InvalidArgumentException(sprintf('The slug "%s" is not well formed: use lowercase letters and digits joined by single hyphens, such as a-quiet-week.', $value));
        }
    }

    /**
     * @throws InvalidArgumentException when the text is not a well-formed slug
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
