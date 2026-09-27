<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use FilesystemIterator;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The source the architecture tests check: packages/src and workbench/app, read once per
 * process. Tests and config files are not domain code, so only the strict_types rule
 * reaches them.
 */
final class Codebase
{
    /**
     * The one namespace that may send HTTP requests (GUARDRAILS 3). Everything outbound
     * goes through the SSRF guard there.
     */
    public const string GATEWAY = 'Cbox\Cms\Core\Egress';

    /** @var list<SourceFile>|null */
    private static ?array $code = null;

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<SourceFile>
     */
    public static function code(): array
    {
        return self::$code ??= array_map(
            SourceFile::read(...),
            self::phpFiles([...self::packageSourceDirectories(), self::root().'/workbench/app']),
        );
    }

    /**
     * Every PHP file that must declare strict types: the packages, the tests, the workbench,
     * the monorepo tooling in tools/ and the tool configuration in the root.
     *
     * @return list<SourceFile>
     */
    public static function allPhpFiles(): array
    {
        $root = self::root();

        return array_map(SourceFile::read(...), [
            ...self::phpFiles([$root.'/packages', $root.'/tests', $root.'/tools', $root.'/workbench']),
            ...(glob($root.'/*.php') ?: []),
        ]);
    }

    /**
     * @return list<DeclaredType>
     */
    public static function types(): array
    {
        return array_merge(...array_map(static fn (SourceFile $file): array => $file->types, self::code()));
    }

    /**
     * The types declared in packages/src, which carry a stability attribute.
     *
     * @return list<DeclaredType>
     */
    public static function packageTypes(): array
    {
        $packages = self::root().'/packages/';

        return array_values(array_filter(
            self::types(),
            static fn (DeclaredType $type): bool => str_starts_with($type->path, $packages),
        ));
    }

    /**
     * The fully qualified names of the types in the given layers.
     *
     * @return list<class-string>
     */
    public static function classesIn(Layer ...$layers): array
    {
        return self::names(array_filter(
            self::types(),
            static fn (DeclaredType $type): bool => in_array($type->layer(), $layers, true),
        ));
    }

    /**
     * What Infrastructure may use (GUARDRAILS 2.5 with 2.2, "Hvor ting bor" in CLAUDE.md): the
     * domain and the contracts, Infrastructure itself, Illuminate\Database, the Boundary row
     * mappers and error readers that turn what Postgres returns into typed values, and the
     * Eloquent casts in Adapter. Anything else, the rest of Adapter and the framework included,
     * is outside the layer.
     *
     * @return list<string>
     */
    public static function infrastructureMayUse(): array
    {
        return [
            ...self::classesIn(Layer::Domain, Layer::Infrastructure, Layer::Boundary),
            ...array_values(array_filter(self::classesIn(Layer::Adapter), self::isCast(...))),
            'Illuminate\Database',
        ];
    }

    /**
     * Whether the class is an Eloquent cast, the only part of Adapter an Eloquent model in
     * Infrastructure may name.
     */
    public static function isCast(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $interfaces = class_implements($class);

        return isset($interfaces[CastsAttributes::class]) || isset($interfaces[CastsInboundAttributes::class]);
    }

    /**
     * @return list<class-string>
     */
    public static function classesInCategory(Category $category): array
    {
        return self::names(array_filter(
            self::types(),
            static fn (DeclaredType $type): bool => $type->category() === $category,
        ));
    }

    /**
     * Every type outside the gateway namespace.
     *
     * @return list<class-string>
     */
    public static function classesOutsideGateway(): array
    {
        return self::names(array_filter(
            self::types(),
            static fn (DeclaredType $type): bool => $type->namespace !== self::GATEWAY
                && ! str_starts_with($type->namespace, self::GATEWAY.'\\'),
        ));
    }

    public static function relative(string $path): string
    {
        return str_starts_with($path, self::root().'/') ? substr($path, strlen(self::root()) + 1) : $path;
    }

    /**
     * @param  array<DeclaredType>  $types
     * @return list<class-string>
     */
    private static function names(array $types): array
    {
        return array_values(array_map(static fn (DeclaredType $type): string => $type->fqcn(), $types));
    }

    /**
     * @return list<string>
     */
    private static function packageSourceDirectories(): array
    {
        return glob(self::root().'/packages/*/src', GLOB_ONLYDIR) ?: [];
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private static function phpFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
            ));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
