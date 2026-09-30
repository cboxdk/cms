<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

/**
 * The php-baseimages dev image the gates run in, locally as in CI (PROGRESS.md, "Beslutninger fra
 * Sylvester", 30 September): PHP 8.5 with PCOV, Xdebug and SPX, Node 22 and Playwright's Chromium
 * in /ms-playwright. compose.yaml's php service, docker/ci.Dockerfile and .github/workflows/ci.yml
 * name the same image; tests/Feature/Tooling/DevImage holds them to IMAGE.
 *
 * A process runs in the image when the image's own variable CBOX_IMAGE_TIER is `dev`: in a
 * container of this image, in the CI container built on it and in the php service of
 * compose.yaml. There `composer check` and the other commands run in place; anywhere else they
 * start a container of the image for the checkout (DevImageRun).
 */
final readonly class DevImage
{
    public const string IMAGE = 'ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1';

    /** The variable the image sets to its tier. */
    public const string TIER_VARIABLE = 'CBOX_IMAGE_TIER';

    public const string TIER = 'dev';

    /**
     * Where HOME is in the container. The host user has no entry in the image's /etc/passwd, so
     * HOME would be /, which it cannot write; compose.yaml's php service uses the same.
     */
    public const string HOME = '/tmp';

    /**
     * Whether a process whose CBOX_IMAGE_TIER is $tier runs in the dev image.
     */
    public static function runsIn(string|false|null $tier): bool
    {
        return $tier === self::TIER;
    }
}
