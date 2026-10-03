<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\Registry\Boundary\LocalFiles;
use Cbox\Cms\Panel\Branding\Domain\BrandImageType;
use Cbox\Cms\Panel\Branding\Domain\BrandRole;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandFile;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandImage;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the installation's brand from cbox-cms.panel.branding (PRD 13.4, Sylvester, 2 October
 * 2026), every key optional:
 *
 *     'branding' => [
 *         'root' => null,
 *         'name' => 'Acme Content',
 *         'logo' => ['light' => 'resources/brand/logo.svg', 'dark' => 'resources/brand/logo-dark.svg', 'alt' => 'Acme'],
 *         'login' => null,
 *         'favicon' => 'resources/brand/favicon.png',
 *     ],
 *
 * - root: the directory every brand file lies inside, the application's base path when null;
 * - name: the product name, 1 to 60 characters without control characters;
 * - logo: the shell header's logo, a file for the light and the dark mode and its alternative
 *   text, 1 to 150 characters;
 * - login: the login page's brand image, of the logo's form; the logo when null;
 * - favicon: one file.
 *
 * A file is a path relative to the root, or an absolute one, that names no stream wrapper,
 * resolves inside the root, and holds a PNG or an SVG document of at most MAX_BYTES, judged by its
 * bytes. An SVG with a script, an event handler attribute or a foreignObject is refused, because
 * the panel serves it from its own origin. Every reason is collected into one InvalidBranding.
 */
#[Internal]
final readonly class BrandingConfig
{
    public const string KEY = 'cbox-cms.panel.branding';

    /** The largest brand file. */
    public const int MAX_BYTES = 524288;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** An SVG document: an optional byte order mark, XML declaration, comments and doctype, then the svg root. */
    private const string SVG = '/\A(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*\?>\s*)?(?:<!--.*?-->\s*)*(?:<!DOCTYPE\s+svg[^>]*>\s*)?(?:<!--.*?-->\s*)*<svg[\s>].*<\/svg>\s*\z/is';

    /** What an SVG the panel serves from its own origin may not hold. */
    private const string SVG_ACTIVE = '/<script|<foreignObject|\son[a-z]+\s*=|javascript:/i';

    private const array KEYS = ['favicon', 'login', 'logo', 'name', 'root'];

    private const array IMAGE_KEYS = ['alt', 'dark', 'light'];

    /**
     * @throws InvalidBranding
     */
    public static function read(Repository $config, string $basePath): Branding
    {
        $setting = $config->get(self::KEY);

        if ($setting === null) {
            return new Branding;
        }

        if (! is_array($setting) || ($setting !== [] && array_is_list($setting))) {
            throw new InvalidBranding([sprintf('%s must be a map with the keys %s; it is %s.', self::KEY, implode(', ', self::KEYS), get_debug_type($setting))]);
        }

        $reasons = [];

        foreach (array_keys($setting) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                $reasons[] = sprintf('%s.%s is not a key of the branding; the keys are %s.', self::KEY, $key, implode(', ', self::KEYS));
            }
        }

        $root = self::root($setting['root'] ?? null, $basePath, $reasons);
        $name = self::name($setting['name'] ?? null, $reasons);
        $logo = self::image($setting['logo'] ?? null, 'logo', BrandRole::LogoLight, BrandRole::LogoDark, $root, $reasons);
        $login = self::image($setting['login'] ?? null, 'login', BrandRole::LoginLight, BrandRole::LoginDark, $root, $reasons);
        $favicon = ($setting['favicon'] ?? null) === null ? null : self::file($setting['favicon'], 'favicon', BrandRole::Favicon, $root, $reasons);

        if ($reasons !== []) {
            throw new InvalidBranding($reasons);
        }

        return new Branding($name, $logo, $login, $favicon);
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function root(mixed $root, string $basePath, array &$reasons): ?string
    {
        $root ??= $basePath;
        $real = is_string($root) && ! LocalPath::namesStreamWrapper($root) ? realpath($root) : false;

        if ($real === false || ! is_dir($real)) {
            $reasons[] = sprintf('%s.root must be the directory the brand files lie inside, the application\'s base path when null; %s is not a readable local directory.', self::KEY, is_string($root) ? '"'.$root.'"' : get_debug_type($root));

            return null;
        }

        return $real;
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function name(mixed $name, array &$reasons): ?string
    {
        if ($name === null) {
            return null;
        }

        if (! self::isText($name, Branding::NAME_MAX_LENGTH)) {
            $reasons[] = sprintf('%s.name must be the product name, 1 to %d characters of text without control characters; it is %s.', self::KEY, Branding::NAME_MAX_LENGTH, self::describe($name));

            return null;
        }

        return trim($name);
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function image(mixed $image, string $key, BrandRole $lightRole, BrandRole $darkRole, ?string $root, array &$reasons): ?BrandImage
    {
        if ($image === null) {
            return null;
        }

        $at = self::KEY.'.'.$key;

        if (! is_array($image) || array_is_list($image)) {
            $reasons[] = sprintf('%s must be a map with light, dark and alt: a file for each mode and the text a screen reader announces; it is %s.', $at, get_debug_type($image));

            return null;
        }

        foreach (array_keys($image) as $part) {
            if (! in_array($part, self::IMAGE_KEYS, true)) {
                $reasons[] = sprintf('%s.%s is not a key of a brand image; the keys are alt, dark and light.', $at, $part);
            }
        }

        $alt = $image['alt'] ?? null;

        if (! self::isText($alt, Branding::ALT_MAX_LENGTH)) {
            $reasons[] = sprintf('%s.alt must be the alternative text a screen reader announces for the image, 1 to %d characters; it is %s.', $at, Branding::ALT_MAX_LENGTH, self::describe($alt));
        }

        $light = self::file($image['light'] ?? null, $key.'.light', $lightRole, $root, $reasons);
        $dark = self::file($image['dark'] ?? null, $key.'.dark', $darkRole, $root, $reasons);

        return $light instanceof BrandFile && $dark instanceof BrandFile && is_string($alt) ? new BrandImage($light, $dark, trim($alt)) : null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function file(mixed $path, string $key, BrandRole $role, ?string $root, array &$reasons): ?BrandFile
    {
        $at = self::KEY.'.'.$key;

        if (! is_string($path) || $path === '') {
            $reasons[] = sprintf('%s must be the path of an SVG or PNG file inside the application; it is %s.', $at, self::describe($path));

            return null;
        }

        if ($root === null) {
            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : $root.'/'.$path;
        $real = LocalPath::namesStreamWrapper($absolute) ? false : realpath($absolute);

        if ($real === false || ! str_starts_with($real, rtrim($root, '/').'/')) {
            $reasons[] = sprintf('%s names "%s", which is not a file inside the application (%s).', $at, $path, $root);

            return null;
        }

        $contents = filesize($real) > self::MAX_BYTES ? null : LocalFiles::read($real);
        $type = $contents === null ? null : self::type($contents);

        if ($contents === null || ! $type instanceof BrandImageType) {
            $reasons[] = sprintf('%s names "%s", which is not a readable SVG or PNG of at most %d KiB, or is an SVG with a script, an event handler or a foreignObject.', $at, $path, intdiv(self::MAX_BYTES, 1024));

            return null;
        }

        return new BrandFile($role, $type, $contents);
    }

    private static function type(string $contents): ?BrandImageType
    {
        if (str_starts_with($contents, self::PNG_SIGNATURE)) {
            return BrandImageType::Png;
        }

        if (preg_match(self::SVG, $contents) === 1 && preg_match(self::SVG_ACTIVE, $contents) !== 1) {
            return BrandImageType::Svg;
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isText(mixed $value, int $maxLength): bool
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        $text = trim($value);

        return $text !== '' && mb_strlen($text) <= $maxLength && preg_match('/\p{Cc}/u', $text) !== 1;
    }

    private static function describe(mixed $value): string
    {
        if (! is_string($value)) {
            return $value === null ? 'missing' : get_debug_type($value);
        }

        return sprintf('"%s"', mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'...' : $value);
    }
}
