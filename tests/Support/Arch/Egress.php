<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Core\Doctor\Adapter\ProcessToolProbe;
use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Cli\Console\SchemaEditorCommand;
use Cbox\Cms\Generators\Editor\Adapter\FilesystemSchemaFiles;
use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Migrations\Boundary\LockFiles;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintFiles;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Panel\Boundary\PanelAssetResponse;
use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Testkit\Phpstan\EgressNames;
use Cbox\Cms\Testkit\Phpstan\LaravelBootLock;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Phpstan\RawSqlRule;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;

/**
 * What only the egress gateway may call (GUARDRAILS 3): everything outbound goes through the SSRF
 * guard in Codebase::GATEWAY. The gateway is not exempt as a namespace: its adapter is allowed
 * exactly the names it uses, as every other class with an allowance is.
 *
 * That is more than the HTTP clients. PHP's URL wrappers make every function that opens a file
 * name fetch http://, https:// and ftp:// URLs: fopen, file, readfile, copy, SplFileObject,
 * SplFileInfo::openFile, DOMDocument::load, XMLReader::open and the others below. ftp:// also
 * writes (file_put_contents), moves and removes files (rename, unlink), makes and removes
 * directories (mkdir, rmdir) and lists them (opendir, scandir, dir, the directory iterators), and a
 * wrapper a package registers can also take touch, chmod, chown and chgrp. The framework's
 * filesystems (Illuminate\Filesystem with the File facade, Symfony's Filesystem) pass a URL on to
 * them. The stat functions (is_file, file_exists, filesize and the like) are not listed: they only
 * look, and the local code calls them everywhere. An XML parser fetches external entities and XSLT's document(), and mail() and
 * error_log() send mail. Sockets connect anywhere, and a process can run curl.
 *
 * The framework and the packages it brings send HTTP of their own, which allow_url_fopen does not
 * stop: a storage disk on s3, ftp or sftp (the Storage facade, the filesystem contracts,
 * Flysystem, the AWS SDK), the mail transports for Mailgun, Postmark, SES and Resend (Laravel's
 * and Symfony's mailers), notifications such as Slack webhooks, and the image manager's
 * fromUrl(). A class gets one without naming the client when it type-hints a contract, so the
 * contracts are listed with the clients: Symfony's HttpClientInterface, HTTPlug and its
 * discovery, and Laravel's filesystem, mail and notification contracts. The container also hands
 * them out by id, app('filesystem')->disk('s3') or app('mailer'), so SERVICE_IDS lists those.
 *
 * The rule matches exact names, read by ReferenceScan, also where a string names one: a string
 * callable such as array_map('file_get_contents', ...), a class resolved from the container by
 * its name, or a container id.
 *
 * The allowances are the local uses the code has, each with the reason it stays local: the local
 * readers and writers refuse a path that names a stream wrapper (LocalPath::namesStreamWrapper())
 * before they touch it, or take their directory from a type that is an absolute path. Every
 * allowance must still be in use, so one cannot outlive the code it was made for. At run time
 * allow_url_fopen=Off (php.allow_url_fopen in cms:doctor) turns the URL wrappers off as well.
 */
final class Egress
{
    /**
     * The lists live in the testkit's EgressNames, because the testkit's HookIoRule reports the
     * same names in a hook (PRD 6.3); each entry's reason is there.
     */
    public const array FUNCTIONS = EgressNames::FUNCTIONS;

    public const array FUNCTION_PREFIXES = EgressNames::FUNCTION_PREFIXES;

    public const array CLASSES = EgressNames::CLASSES;

    public const array SERVICE_IDS = EgressNames::SERVICE_IDS;

    public const array METHODS = EgressNames::METHODS;

    /**
     * Each class that may use a name from the lists above, and the names it may use.
     *
     * @var array<class-string, list<string>>
     */
    public const array ALLOWED = [
        // The egress gateway itself (GUARDRAILS 3, PRD 7.14): Laravel's HTTP client, its Factory and
        // its ConnectionException, with cboxdk/laravel-ssrf's GuardRequestMiddleware on every request,
        // which checks the URL the request is sent to and pins its connection, redirects off. The
        // gateway is held to exactly these names like any other class; nothing else in its
        // namespace may use a name from the lists.
        SsrfEgressGateway::class => ['Illuminate\Http\Client\\'],
        // Reads local files: it refuses a path that names a stream wrapper
        // (LocalPath::namesStreamWrapper()) before it touches it, and the generators read every
        // schema and generated file through it.
        LocalFile::class => ['SplFileObject'],
        // Reads the kernel's envelope.v1.json, which the MCP tools embed in their input schemas,
        // from a path fixed below the mcp module's directory in the same package, and refuses a
        // path that names a stream wrapper before it touches it.
        KernelSchemas::class => ['SplFileObject'],
        // Reads the panel's Vite manifest, .vite/manifest.json below the build directory that
        // PanelServiceProvider gives it, an absolute path below the panel module's directory; it
        // refuses a directory that names a stream wrapper before it touches it.
        ViteManifest::class => ['SplFileObject'],
        // Reads a file of the panel's build that the build's manifest names, below the build's
        // directory, which PanelBuild requires to be an absolute local path, and refuses a path
        // that names a stream wrapper before it touches it.
        PanelAssetResponse::class => ['SplFileObject'],
        // Lists a scan root with a RecursiveDirectoryIterator and reads the .php files it finds
        // there; ScanRoot requires the directory to be an absolute path, and the scanner lists its
        // realpath(), which resolves no stream wrapper.
        AttributeScanner::class => ['FilesystemIterator', 'openfile', 'RecursiveDirectoryIterator'],
        // Makes, lists, writes, renames into place and removes files in bootstrap/cache/cms below
        // the application's bootstrap path, the only directory CoreServiceProvider gives it, under
        // the fixed names of RegistryName, and opens its lock file, FileRegistryCache::LOCK_FILE,
        // there; write() refuses a directory that names a stream wrapper before it does any of it.
        FileRegistryCache::class => ['fopen', 'file_put_contents', 'mkdir', 'rename', 'scandir', 'unlink'],
        // Reads the kernel's receipt.v1.json and problem.v1.json below the root of cboxdk/cms with
        // an SplFileObject, and writes a temporary file next to openapi.json in the registry cache's directory, which
        // CoreServiceProvider gives it, renames it into place and removes it when that fails; it
        // refuses a path that names a stream wrapper before it touches it.
        FileOpenApiDocuments::class => ['file_put_contents', 'rename', 'SplFileObject', 'unlink'],
        // Lists a schema root, whose base SchemaRoot requires to be an absolute path, which names
        // no stream wrapper.
        BlueprintFiles::class => ['FilesystemIterator', 'RecursiveDirectoryIterator'],
        // Lists the schema locks in the migrations directory below cbox-cms.generators.root, which
        // GenerationTarget requires to be an absolute path; read() refuses a path that names a
        // stream wrapper before it lists it.
        LockFiles::class => ['FilesystemIterator'],
        // Makes, lists, writes, renames into place and removes files in the owned directories
        // below cbox-cms.generators.root, which GenerationTarget requires to be an absolute path; write()
        // refuses a root that names a stream wrapper.
        FilesystemGeneratedOutput::class => ['file_put_contents', 'FilesystemIterator', 'mkdir', 'RecursiveDirectoryIterator', 'rename', 'unlink'],
        // Writes a temporary file next to the realpath() of a schema file it found by listing a
        // schema root, gives it the file's permissions, renames it into place and removes it when
        // that fails; write() refuses a path that names a stream wrapper, and realpath() resolves
        // none.
        FilesystemSchemaFiles::class => ['chmod', 'file_put_contents', 'rename', 'unlink'],
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
     * Each class with a string that spells a name in the lists but is a word, never called or
     * resolved, and the strings, exactly as written. The name itself stays forbidden there.
     *
     * @var array<class-string, list<string>>
     */
    public const array ALLOWED_WORDS = [
        // "Generated 1 file: ..." and "Generated 2 files: ...", the noun in the report of
        // cms:generate.
        GenerateCommand::class => ['file', 'files'],
        // "Wrote the editor line to 1 file" and "... to 2 files", the noun in the report of
        // cms:schema:editor.
        SchemaEditorCommand::class => ['file', 'files'],
        // The member "file" of a chunk in Vite's manifest, the chunk's output file, which it reads
        // from the decoded JSON, never a function it calls.
        ViteManifest::class => ['file'],
        // PDO::exec(), one of the PDO methods that take SQL, which the rule compares a method
        // call's name with.
        RawSqlRule::class => ['exec'],
        // IssuerKind::System, the issuer kind "system" that PRD 5.5 names, a value of the
        // envelope and the changeset.
        IssuerKind::class => ['system'],
        // The type "http" of the OpenAPI document's security scheme for a Bearer credential, a
        // value of the document it writes, never a container id it resolves.
        OpenApiJson::class => ['http'],
        // The lists themselves, which the Arch suite here and the testkit's HookIoRule read, and the
        // short names of the facades File and Mail, which FACADES joins to their namespace.
        EgressNames::class => [...EgressNames::WORDS, 'File', 'Mail'],
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

                if ($forbidden === null || self::allowed($file, $reference, $forbidden)) {
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

                    if ($reference->kind === ReferenceKind::StringLiteral) {
                        $used[$type->fqcn().' word '.$reference->name] = true;
                    }
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

        foreach (self::ALLOWED_WORDS as $class => $words) {
            foreach ($words as $word) {
                if (! isset($used[$class.' word '.$word])) {
                    $unused[] = $class.' word '.$word;
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
            ReferenceKind::Method => self::forbiddenMethod($reference->name),
            ReferenceKind::ClassName => self::forbiddenClass($reference->name),
            ReferenceKind::StringLiteral => self::forbiddenString($reference->name),
        };
    }

    /**
     * A string names a container id, a function, a method of an array callable, a class, or
     * Class::method.
     */
    private static function forbiddenString(string $value): ?string
    {
        if (in_array($value, self::SERVICE_IDS, true)) {
            return $value;
        }

        if (str_contains($value, '::')) {
            [$class, $method] = explode('::', $value, 2);

            return self::forbiddenClass($class) ?? self::forbiddenMethod($method);
        }

        return self::forbiddenFunction(strtolower($value)) ?? self::forbiddenClass($value) ?? self::forbiddenMethod($value);
    }

    private static function forbiddenMethod(string $method): ?string
    {
        $lower = strtolower($method);

        return in_array($lower, self::METHODS, true) ? $lower : null;
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

    private static function allowed(SourceFile $file, Reference $reference, string $forbidden): bool
    {
        return array_any($file->types, static fn (DeclaredType $type): bool => in_array($forbidden, self::ALLOWED[$type->fqcn()] ?? [], true)
            || ($reference->kind === ReferenceKind::StringLiteral && in_array($reference->name, self::ALLOWED_WORDS[$type->fqcn()] ?? [], true)));
    }
}
