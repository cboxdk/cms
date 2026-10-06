<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeDeclarations;

/**
 * The names a scaffold gives things: the module of a contribution from the local part of its id,
 * `approvals.slug-hint` giving SlugHint, the TypeScript name of a command's document and a query's
 * result as cms:panel:types writes them, and the directory the addon's panel code lives in.
 */
#[Internal]
final readonly class ScaffoldNames
{
    /** The directory of the addon's panel code, below its package; the generated types are beside it. */
    public const string SOURCE = 'resources/panel/src';

    private function __construct() {}

    /**
     * The PascalCase name of the contribution's local part: the id without its namespace.
     */
    public static function module(ContributionId $id): string
    {
        return ShapeDeclarations::pascal(substr($id->value, strlen($id->namespace()->value) + 1));
    }

    /**
     * The camelCase name of the contribution's local part.
     */
    public static function variable(ContributionId $id): string
    {
        return lcfirst(self::module($id));
    }

    /**
     * The TypeScript name of a command's document, as cms:panel:types writes it.
     */
    public static function document(CommandRef $command): string
    {
        return ShapeDeclarations::pascal($command->name->value).'V'.$command->version;
    }

    /**
     * The TypeScript name of a data query's result, as cms:panel:types writes it.
     */
    public static function result(CommandRef $query): string
    {
        return ShapeDeclarations::pascal($query->name->value).'ResultV'.$query->version;
    }

    /**
     * The short name of a class.
     */
    public static function short(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * A string as a TypeScript single-quoted literal.
     */
    public static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
