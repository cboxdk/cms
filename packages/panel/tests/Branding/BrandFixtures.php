<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Branding;

/**
 * A scratch application directory with brand files: logos in SVG and PNG, and files that are not
 * a readable SVG or PNG, for the branding's tests. remove() deletes it.
 */
final readonly class BrandFixtures
{
    public const string LIGHT = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="32"><rect width="96" height="32" fill="#14532d"/></svg>';

    public const string DARK = "<?xml version=\"1.0\"?>\n<!-- the dark logo -->\n<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"96\" height=\"32\"><rect width=\"96\" height=\"32\" fill=\"#86efac\"/></svg>\n";

    public string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()).'/cms-brand-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/brand', 0o775, true);
        $this->write('brand/logo.svg', self::LIGHT);
        $this->write('brand/logo-dark.svg', self::DARK);
        $this->write('brand/favicon.png', self::png());
        $this->write('brand/logo.txt', 'a logo');
        $this->write('brand/script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->write('brand/handler.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>');
        $this->write('brand/huge.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 600000));
    }

    public function write(string $path, string $contents): void
    {
        file_put_contents($this->root.'/'.$path, $contents);
    }

    public function remove(): void
    {
        foreach (glob($this->root.'/brand/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root.'/brand');
        rmdir($this->root);
    }

    /**
     * A PNG of one pixel.
     */
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==', true);
    }
}
