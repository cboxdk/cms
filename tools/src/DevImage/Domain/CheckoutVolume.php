<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

use InvalidArgumentException;

/**
 * A Docker volume that holds one directory of a checkout for the dev image (VolumeKind), mounted
 * over `<checkout>/<directory>` in the container. The name is `laravel-cms-<kind>-` and the first
 * 12 hex digits of the SHA-256 of the checkout's real path, so every checkout, a linked worktree
 * included, has its own; the label names the checkout, so `composer image:prune` can find the
 * volumes of removed checkouts.
 */
final readonly class CheckoutVolume
{
    public const string PREFIX = 'laravel-cms-';

    /** The label that names the checkout a volume belongs to. */
    public const string LABEL = 'dev.cbox.cms.checkout';

    private function __construct(
        public string $name,
        public VolumeKind $kind,
        public string $checkout,
    ) {}

    public static function for(VolumeKind $kind, string $checkout): self
    {
        if (! str_starts_with($checkout, '/')) {
            throw new InvalidArgumentException("A volume of the dev image belongs to a checkout's absolute path, not [{$checkout}].");
        }

        return new self(self::PREFIX.$kind->value.'-'.substr(hash('sha256', $checkout), 0, 12), $kind, $checkout);
    }

    /**
     * Every volume of a checkout, in the order they are mounted.
     *
     * @return list<self>
     */
    public static function all(string $checkout): array
    {
        return array_map(static fn (VolumeKind $kind): self => self::for($kind, $checkout), VolumeKind::cases());
    }

    /**
     * The kind of a volume name this class makes, or null for any other name.
     */
    public static function kindOf(string $volume): ?VolumeKind
    {
        foreach (VolumeKind::cases() as $kind) {
            if (preg_match('/^'.preg_quote(self::PREFIX.$kind->value.'-', '/').'[0-9a-f]{12}$/', $volume) === 1) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Why `composer image:prune` removes a volume, or null when it keeps it: a volume of this
     * class is removed when the checkout its label names is no longer a directory, or no longer
     * derives the volume's name, as after a worktree was removed and another made at a path that
     * resolves elsewhere. $realPath is the checkout's real path, false when it is not a directory.
     */
    public static function staleReason(string $volume, string $checkout, string|false $realPath): ?string
    {
        $kind = self::kindOf($volume);

        if (! $kind instanceof VolumeKind) {
            return null;
        }

        if ($realPath === false) {
            return "its checkout {$checkout} is gone";
        }

        if (self::for($kind, $realPath)->name !== $volume) {
            return "its checkout {$checkout} resolves to {$realPath}, whose volume has another name";
        }

        return null;
    }

    /** Where the volume is mounted in the container. */
    public function mountPoint(): string
    {
        return $this->checkout.'/'.$this->kind->directory();
    }
}
