<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Core\Doctor\Adapter\ProcessToolProbe;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Cbox\Cms\Testkit\Phpstan\LaravelBootLock;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * What only the egress gateway may call (GUARDRAILS 3): everything outbound goes through the SSRF
 * guard in Codebase::GATEWAY.
 *
 * That is more than the HTTP clients. PHP's URL wrappers make every function that opens a file
 * name fetch http://, https:// and ftp:// URLs: fopen, file, readfile, copy, SplFileObject,
 * SplFileInfo::openFile, DOMDocument::load, XMLReader::open and the others below. Sockets connect
 * anywhere, and a process can run curl. The rule matches exact names, read by ReferenceScan.
 *
 * The allowances are the local uses the code has, each with the reason it stays local. Every
 * allowance must still be in use, so one cannot outlive the code it was made for. At run time
 * allow_url_fopen=Off (php.allow_url_fopen in cms:doctor) turns the URL wrappers off as well.
 */
final class Egress
{
    /**
     * Functions that take a file name, which the URL wrappers also fetch, open a socket or a
     * stream context for one, or run a program.
     */
    public const array FUNCTIONS = [
        // Reading or fetching a file name.
        'copy',
        'exif_imagetype',
        'exif_read_data',
        'exif_thumbnail',
        'file',
        'file_get_contents',
        'finfo_file',
        'fopen',
        'get_headers',
        'get_meta_tags',
        'getimagesize',
        'gzfile',
        'gzopen',
        'hash_file',
        'hash_hmac_file',
        'highlight_file',
        'md5_file',
        'mime_content_type',
        'parse_ini_file',
        'php_strip_whitespace',
        'readfile',
        'readgzfile',
        'sha1_file',
        'show_source',
        'simplexml_load_file',
        // Sockets and stream contexts.
        'fsockopen',
        'pfsockopen',
        'stream_context_create',
        'stream_context_set_default',
        'stream_socket_client',
        // Programs, which can run curl or wget.
        'exec',
        'passthru',
        'pcntl_exec',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /**
     * Families of functions: cURL, FTP, the sockets extension and GD's loaders, which take a file
     * name.
     */
    public const array FUNCTION_PREFIXES = ['curl_', 'ftp_', 'imagecreatefrom', 'socket_'];

    /**
     * Classes, and namespaces ending in a backslash, that fetch a file name or a URL, send HTTP or
     * run a program.
     */
    public const array CLASSES = [
        'DOMDocument',
        'finfo',
        'SimpleXMLElement',
        'SoapClient',
        'SplFileObject',
        'XMLReader',
        'XMLWriter',
        'GuzzleHttp\\',
        'Illuminate\Http\Client\\',
        'Illuminate\Process\\',
        Http::class,
        Process::class,
        'Psr\Http\Client\\',
        'Symfony\Component\HttpClient\\',
        'Symfony\Component\Process\\',
    ];

    /** Methods that open a file name: SplFileInfo::openFile(). */
    public const array METHODS = ['openfile'];

    /**
     * Each class that may use a name from the lists above, and the names it may use.
     *
     * @var array<class-string, list<string>>
     */
    public const array ALLOWED = [
        // Reads local files: it refuses a path that names a stream wrapper (LocalFile::WRAPPER)
        // before it touches it, and the generators read every schema and generated file through it.
        LocalFile::class => ['SplFileObject'],
        // Reads the .php files that a RecursiveDirectoryIterator finds below a scan root, whose
        // directory ScanRoot requires to be an absolute path.
        AttributeScanner::class => ['openfile'],
        // Runs `node --version` and `node -e` with a fixed script for cms:doctor --dev.
        ProcessToolProbe::class => ['Symfony\Component\Process\\'],
        // The testkit: PHPStan's analysed files, a lock file in the temporary directory, and child
        // PHP processes of a test.
        PhpstanIgnoreCollector::class => ['SplFileObject'],
        LaravelBootLock::class => ['SplFileObject'],
        ChildProcess::class => ['Symfony\Component\Process\\'],
        ChildProcesses::class => ['Symfony\Component\Process\\'],
    ];

    /**
     * The references outside the gateway to a name in the lists, one line each, minus the
     * allowances.
     *
     * @param  list<SourceFile>  $files
     * @return list<string>
     */
    public static function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            foreach ($file->references as $reference) {
                $forbidden = self::forbidden($reference);

                if ($forbidden === null || self::inGateway($reference->namespace) || self::allowed($file, $forbidden)) {
                    continue;
                }

                $violations[] = sprintf('%s: %s %s', Codebase::relative($reference->location()), $reference->kind->value, $reference->name);
            }
        }

        return $violations;
    }

    /**
     * The allowances no file uses any more.
     *
     * @param  list<SourceFile>  $files
     * @return list<string>
     */
    public static function unusedAllowances(array $files): array
    {
        $used = [];

        foreach ($files as $file) {
            foreach ($file->references as $reference) {
                $forbidden = self::forbidden($reference);

                if ($forbidden === null) {
                    continue;
                }

                foreach ($file->types as $type) {
                    $used[$type->fqcn().' '.$forbidden] = true;
                }
            }
        }

        $unused = [];

        foreach (self::ALLOWED as $class => $names) {
            foreach ($names as $name) {
                if (! isset($used[$class.' '.$name])) {
                    $unused[] = $class.' '.$name;
                }
            }
        }

        return $unused;
    }

    /**
     * The list entry a reference matches, or null.
     */
    public static function forbidden(Reference $reference): ?string
    {
        return match ($reference->kind) {
            ReferenceKind::Function => self::forbiddenFunction($reference->name),
            ReferenceKind::Method => in_array($reference->name, self::METHODS, true) ? $reference->name : null,
            ReferenceKind::ClassName => self::forbiddenClass($reference->name),
        };
    }

    private static function forbiddenFunction(string $function): ?string
    {
        if (in_array($function, self::FUNCTIONS, true)) {
            return $function;
        }

        foreach (self::FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($function, $prefix)) {
                return $function;
            }
        }

        return null;
    }

    private static function forbiddenClass(string $class): ?string
    {
        $lower = strtolower($class);

        foreach (self::CLASSES as $entry) {
            $entryLower = strtolower($entry);
            $matches = str_ends_with($entry, '\\')
                ? str_starts_with($lower, $entryLower) || $lower === rtrim($entryLower, '\\')
                : $lower === $entryLower;

            if ($matches) {
                return $entry;
            }
        }

        return null;
    }

    private static function inGateway(string $namespace): bool
    {
        return $namespace === Codebase::GATEWAY || str_starts_with($namespace, Codebase::GATEWAY.'\\');
    }

    private static function allowed(SourceFile $file, string $forbidden): bool
    {
        return array_any($file->types, fn (DeclaredType $type): bool => in_array($forbidden, self::ALLOWED[$type->fqcn()] ?? [], true));
    }
}
