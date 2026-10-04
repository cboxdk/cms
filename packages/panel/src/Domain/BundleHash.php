<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledBundle;

/**
 * The hash of an addon's panel bundle as cms:build compiled it (PRD 13.4): the SHA-256, in
 * lowercase hexadecimal, of the path and SHA-384 of each of its files, sorted by path and one per
 * line. The panel serves the bundle's files below it, so a file's address changes with any file
 * of the bundle and a browser may keep what it fetched for good, and an address of a build that
 * is gone answers 404.
 */
#[Internal]
final readonly class BundleHash
{
    /** The form of a hash, as the panel's addon asset route takes it. */
    public const string PATTERN = '[0-9a-f]{64}';

    private function __construct() {}

    public static function of(CompiledBundle $bundle): string
    {
        return hash('sha256', implode("\n", array_map(static fn (BundleFile $file): string => $file->path->value.' '.$file->integrity->value, $bundle->files)));
    }
}
