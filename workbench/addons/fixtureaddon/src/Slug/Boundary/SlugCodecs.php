<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;

/**
 * The CommandCodec of fixtureaddon.slug.set, version 1, which the addon's provider registers under
 * CommandCodecs::TAG, so the surfaces read the command and write its canonical JSON with it,
 * cms:build describes the command on REST, the panel's command form renders its schema, and
 * cms:panel:types types its document.
 */
final readonly class SlugCodecs
{
    public const string COMMAND = 'fixtureaddon.slug.set';

    public const int VERSION = 1;

    private function __construct() {}

    public static function setSlug(): CommandCodec
    {
        return new CommandCodec(
            new CommandName(self::COMMAND),
            self::VERSION,
            new SetArticleSlugCodec,
            new JsonSchema(SetArticleSlugCodec::SCHEMA),
        );
    }
}
