<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * What the fixture addon knows of the type it extends, the workbench's app:fixture_article: its id
 * and name as the owner's blueprint gives them, the owner's title the slug is derived from, and the
 * addon's own field, ext.fixtureaddon.fixture_slug. The addon's blueprint extension in
 * schema/fixture_article.yaml names the same id.
 */
final readonly class FixtureArticle
{
    /** The type_id of the owner's blueprint, workbench/schema/fixture_article.yaml. */
    public const string TYPE_ID = '01a0df3e-8cef-7e9f-8daf-9faa60f1faa6';

    public const string TYPE = 'app:fixture_article';

    /** The owner's field the slug is derived from. */
    public const string TITLE = 'fixture_title';

    /** The addon's field, in its namespace. */
    public const string SLUG = 'fixture_slug';

    public static function is(TypeId $type): bool
    {
        return $type->equals(TypeId::fromString(self::TYPE_ID));
    }

    public static function title(): FieldHandle
    {
        return new FieldHandle(self::TITLE);
    }

    public static function slug(): FieldHandle
    {
        return new FieldHandle(self::SLUG);
    }

    public static function namespace(): FieldNamespace
    {
        return new FieldNamespace(FixtureAddonServiceProvider::NAMESPACE);
    }
}
