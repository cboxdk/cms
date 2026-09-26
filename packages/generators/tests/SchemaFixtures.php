<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\AddonFieldType;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\Dto\AddonOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds blueprints, resolved schemas, targets and scratch directories for the generator tests.
 */
final class SchemaFixtures
{
    public const string ROOT = '/srv/app';

    /** @var list<string> */
    private static array $scratch = [];

    /**
     * The schema of types in the app's root: type handle to field handle to field type, such as
     * `text` or `acme:colour`, in the given order.
     *
     * @param  array<string, array<string, string>>  $types
     */
    public static function schema(array $types): ResolvedSchema
    {
        $root = self::root();

        return SchemaResolver::resolve(new Blueprints(
            array_map(
                static fn (string $handle, array $fields): TypeBlueprint => self::type($root, $handle, $fields),
                array_keys($types),
                array_values($types),
            ),
            [],
        ));
    }

    /**
     * A schema root of the owner below the base.
     */
    public static function root(string $owner = Owner::APP, string $directory = 'schema', string $base = self::ROOT): SchemaRoot
    {
        return new SchemaRoot(new Owner($owner), $base, $directory);
    }

    /**
     * A type in `<handle>.yaml` of the root, with a type_id made from the owner and the handle.
     *
     * @param  array<string, string>  $fields  field handle to field type
     */
    public static function type(SchemaRoot $root, string $handle, array $fields): TypeBlueprint
    {
        $at = new SourceLocation($root->file($handle.'.yaml'), '');

        return new TypeBlueprint(
            self::typeId($root->owner->value, $handle),
            new Handle($handle),
            ucfirst(str_replace('_', ' ', $handle)),
            null,
            1,
            new Capabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            self::fields($root, $at, $fields),
            $root->owner,
            $at,
        );
    }

    /**
     * An extension in `<path>` of the root that adds fields to the type with the given id.
     *
     * @param  array<string, string>  $fields  field handle to field type
     */
    public static function extension(SchemaRoot $root, string $path, TypeId $extends, array $fields): ExtensionBlueprint
    {
        $at = new SourceLocation($root->file($path), '');

        return new ExtensionBlueprint($extends, 1, self::fields($root, $at, $fields), $root->owner, $at);
    }

    /**
     * The type_id of a type that type() makes: a UUIDv7 from a hash of the owner and the handle.
     */
    public static function typeId(string $owner, string $handle): TypeId
    {
        $hash = hash('sha256', $owner.'/'.$handle);

        return TypeId::fromString(sprintf(
            '%s-%s-7%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 3),
            substr($hash, 15, 3),
            substr($hash, 18, 12),
        ));
    }

    /**
     * Field options for a core field type, with the defaults a blueprint file leaves out, or an
     * addon's field type such as `acme:colour` without options.
     */
    public static function options(string $type): FieldOptions
    {
        return match (CoreFieldType::tryFrom($type)) {
            CoreFieldType::Text => new TextOptions(null, TextOptions::DEFAULT_MAX_LENGTH, TextFormat::Plain),
            CoreFieldType::LongText => new LongTextOptions(null, LongTextOptions::DEFAULT_MAX_LENGTH),
            CoreFieldType::Integer => new IntegerOptions(null, null, null),
            CoreFieldType::Decimal => new DecimalOptions(10, 2, null, null, null),
            CoreFieldType::Boolean => new BooleanOptions,
            CoreFieldType::Date => new DateOptions(null, null),
            CoreFieldType::Datetime => new DatetimeOptions(null, null),
            CoreFieldType::Select => new SelectOptions([new SelectOption(new Handle('one'), 'One')], false, null, null),
            CoreFieldType::RichText => new RichTextOptions(null, null, null, null),
            CoreFieldType::Group => new GroupOptions([self::field(self::root(), new SourceLocation('schema/group.yaml', '/fields/0/fields/0'), 'name', 'text', null)], null),
            null => new AddonOptions(new AddonFieldType($type), null),
        };
    }

    /**
     * The target below the root, with the given schema roots or the app's root `schema`.
     *
     * @param  list<SchemaRoot>  $roots
     */
    public static function target(string $root = self::ROOT, array $roots = []): GenerationTarget
    {
        return new GenerationTarget($root, $roots === [] ? [self::root(base: $root)] : $roots, 'app/Cms/Generated', 'App\Cms\Generated', 'resources/js/cms/generated');
    }

    public static function scratch(): string
    {
        $directory = sys_get_temp_dir().'/cms-generators-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);
        self::$scratch[] = $directory;

        return (string) realpath($directory);
    }

    public static function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * Every file below the directory, relative to it and sorted.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($directory) + 1);
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, string>  $fields  field handle to field type
     * @return list<FieldBlueprint>
     */
    private static function fields(SchemaRoot $root, SourceLocation $at, array $fields): array
    {
        return array_map(
            static fn (string $handle, string $type, int $index): FieldBlueprint => self::field($root, $at->below('fields', $index), $handle, $type, Classification::Public),
            array_keys($fields),
            array_values($fields),
            array_keys(array_keys($fields)),
        );
    }

    private static function field(SchemaRoot $root, SourceLocation $at, string $handle, string $type, ?Classification $classification): FieldBlueprint
    {
        return new FieldBlueprint(
            new Handle($handle),
            ucfirst(str_replace('_', ' ', $handle)),
            'The '.str_replace('_', ' ', $handle).'.',
            false,
            $classification,
            false,
            false,
            true,
            self::options($type),
            $root->owner,
            $at,
        );
    }

    public static function cleanUp(): void
    {
        foreach (self::$scratch as $directory) {
            self::remove($directory);
        }

        self::$scratch = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path) as $entry) {
            if ($entry instanceof SplFileInfo) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    chmod($entry->getPathname(), 0o775);
                }

                self::remove($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
