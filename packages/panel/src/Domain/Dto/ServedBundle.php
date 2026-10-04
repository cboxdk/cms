<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundlePath;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Panel\Domain\BundleHash;
use InvalidArgumentException;

/**
 * An addon's panel bundle as the panel serves it (PRD 13.4): the addon, the absolute directory
 * its manifest names, the hash of the bundle as cms:build compiled it (BundleHash), its entry
 * module and every file with its SHA-384, by path. Only these files are served, each checked
 * against its hash before it is sent.
 */
#[Internal]
final readonly class ServedBundle
{
    /** @var array<string, BundleFile> by path */
    public array $files;

    /**
     * @param  list<BundleFile>  $files
     *
     * @throws InvalidArgumentException when the directory is not an absolute local path, the hash is not a BundleHash or the entry is not a file
     */
    public function __construct(
        public AddonNamespace $addon,
        public string $directory,
        public string $hash,
        public BundlePath $entry,
        array $files,
    ) {
        if (! str_starts_with($directory, '/') || LocalPath::namesStreamWrapper($directory)) {
            throw new InvalidArgumentException("The bundle directory {$directory} of addon {$addon->value} is not an absolute local path.");
        }

        if (preg_match('~\A'.BundleHash::PATTERN.'\z~', $hash) !== 1) {
            throw new InvalidArgumentException("The bundle hash {$hash} of addon {$addon->value} is not a SHA-256.");
        }

        $byPath = [];

        foreach ($files as $file) {
            $byPath[$file->path->value] = $file;
        }

        ksort($byPath, SORT_STRING);
        $this->files = $byPath;

        if (! isset($byPath[$entry->value])) {
            throw new InvalidArgumentException("The entry {$entry->value} of addon {$addon->value} is not a file of its bundle.");
        }
    }

    public function file(string $path): ?BundleFile
    {
        return $this->files[$path] ?? null;
    }

    /**
     * The files of a kind, in path order.
     *
     * @return list<BundleFile>
     */
    public function of(BundleFileKind $kind): array
    {
        return array_values(array_filter($this->files, static fn (BundleFile $file): bool => $file->kind === $kind));
    }

    /**
     * The absolute path of a file of the bundle.
     */
    public function pathOf(BundleFile $file): string
    {
        return rtrim($this->directory, '/').'/'.$file->path->value;
    }
}
