<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Build\ScanRoot;
use InvalidArgumentException;

/**
 * A registry entry was given a value it cannot hold. The registry DTOs check their values, so an
 * entry read from a damaged cache file fails the same way as one built wrongly in code.
 */
#[Experimental]
final class InvalidRegistryEntry extends InvalidArgumentException
{
    /** A fully qualified PHP class name without a leading backslash. */
    private const string CLASS_PATTERN = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    public static function checkClass(string $field, string $class): string
    {
        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            throw new self(sprintf('The %s "%s" is not a fully qualified class name.', $field, $class));
        }

        return $class;
    }

    public static function checkPackage(string $package): string
    {
        if (preg_match(ScanRoot::PACKAGE_PATTERN, $package) !== 1) {
            throw new self(sprintf('The package "%s" is not a Composer package name.', $package));
        }

        return $package;
    }

    public static function because(string $message): self
    {
        return new self($message);
    }
}
