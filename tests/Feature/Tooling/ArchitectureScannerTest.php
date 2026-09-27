<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Comment;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Egress;
use Cbox\Cms\Tests\Support\Arch\GlobalName;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Reference;
use Cbox\Cms\Tests\Support\Arch\SourceFile;
use Illuminate\Support\Facades\Http;

/*
 * The token scanner and the layer map behind the Arch suite. The Arch suite is only as good
 * as these, so they are tested on their own (GUARDRAILS 7.3).
 */

it('maps a namespace to the innermost layer segment, at the end or with segments below it', function (?Layer $expected, string $namespace): void {
    expect(Layer::of($namespace))->toBe($expected);
})->with([
    [Layer::Domain, 'Cbox\Cms\Core\Entries\Domain'],
    [Layer::Domain, 'Cbox\Cms\Core\Entries\Domain\Dto'],
    [Layer::Actions, 'Cbox\Cms\Core\Entries\Actions'],
    [Layer::Adapter, 'Cbox\Cms\Core\Receipts\Adapter\Postgres'],
    [Layer::Infrastructure, 'Cbox\Cms\Core\Entries\Infrastructure\Models'],
    [Layer::Http, 'Cbox\Cms\Http'],
    [Layer::Http, 'Cbox\Cms\Http\Controllers'],
    [Layer::Boundary, 'Cbox\Cms\Http\Boundary'],
    [Layer::Cli, 'Cbox\Cms\Cli\Console'],
    [Layer::Jobs, 'Cbox\Cms\Core\Projections\Jobs'],
    [Layer::Domain, 'Cbox\Cms\Contracts'],
    [Layer::Domain, 'Cbox\Cms\Contracts\Attributes'],
    [null, 'Cbox\Cms\ContractsExtra'],
    [null, 'Cbox\Cms\Core'],
    [null, 'Cbox\Cms\Core\DomainEvents'],
    [null, 'Cbox\Cms\Core\HttpGateway'],
    [null, ''],
]);

it('maps a namespace to its category', function (?Category $expected, string $namespace): void {
    expect(Category::of($namespace))->toBe($expected);
})->with([
    [Category::Commands, 'Cbox\Cms\Core\Entries\Domain\Commands'],
    [Category::Dto, 'Cbox\Cms\Core\Entries\Domain\Dto\Nested'],
    [Category::Receipts, 'Cbox\Cms\Core\Domain\Receipts'],
    [Category::Queries, 'Cbox\Cms\Core\Entries\Domain\Queries'],
    [null, 'Cbox\Cms\Core\Entries\Domain'],
    [null, 'Cbox\Cms\Cli\Console'],
]);

it('finds declared types and skips ::class and anonymous classes', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final readonly class EntryId
        {
            public function name(): string
            {
                $anonymous = new class {};
                $readonly = new readonly class {};

                return self::class.$anonymous::class;
            }
        }

        interface Clock {}

        trait Named {}

        enum Status: string
        {
            case Active = 'active';
        }
        PHP);

    expect(array_map(static fn (DeclaredType $type): string => $type->kind.' '.$type->fqcn(), $file->types))->toBe([
        'class Cbox\Cms\Core\Entries\Domain\EntryId',
        'interface Cbox\Cms\Core\Entries\Domain\Clock',
        'trait Cbox\Cms\Core\Entries\Domain\Named',
        'enum Cbox\Cms\Core\Entries\Domain\Status',
    ])->and($file->types[0]->layer())->toBe(Layer::Domain)
        ->and($file->types[0]->line)->toBe(7);
});

it('finds a phpstan-ignore comment that is attached to no node, with its namespace and line', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final readonly class EntryId {}

        /* a block comment */
        // @phpstan-ignore-next-line
        PHP);

    expect(array_map(static fn (Comment $comment): string => $comment->line.' '.$comment->namespace.' '.trim($comment->text), $file->comments))->toBe([
        '9 Cbox\Cms\Core\Entries\Domain /* a block comment */',
        '10 Cbox\Cms\Core\Entries\Domain // @phpstan-ignore-next-line',
    ]);
});

it('puts a comment before the namespace statement in the global namespace', function (): void {
    $file = SourceFile::parse('Probe.php', "<?php\n\n// @phpstan-ignore-line\ndeclare(strict_types=1);\n\nnamespace Cbox\\Cms\\Core\\Adapter;\n\n/** @phpstan-ignore-next-line */\n");

    expect(array_map(static fn (Comment $comment): string => $comment->namespace, $file->comments))
        ->toBe(['', 'Cbox\Cms\Core\Adapter'])
        ->and($file->declaresStrictTypes)->toBeTrue();
});

it('knows whether a file starts with declare(strict_types=1)', function (bool $expected, string $code): void {
    expect(SourceFile::parse('Probe.php', $code)->declaresStrictTypes)->toBe($expected);
})->with([
    [true, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n"],
    [true, "<?php\n\n/** Header. */\n\ndeclare(strict_types=1);\n"],
    [false, "<?php\n\nreturn [];\n"],
    [false, "<?php\n\ndeclare(strict_types=0);\n"],
    [false, "<?php\n\nnamespace A;\n\ndeclare(strict_types=1);\n"],
    [false, "<html><?php\n\ndeclare(strict_types=1);\n"],
]);

it('records names that resolve from the global namespace, but not trait uses or closure uses', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Adapter;

        use DB;
        use Illuminate\Support\Facades\Http;
        use function strlen;

        final class Store
        {
            use SomeTrait;

            public function run(): int
            {
                $callback = function () use ($value): int {
                    return \Cache::get('x') + \Facades\App\Clock::now();
                };

                return strlen('x');
            }
        }
        PHP);

    expect(array_map(static fn (GlobalName $name): string => $name->name, $file->globalNames))
        ->toBe(['DB', Http::class, 'Cache', 'Facades\App\Clock']);
});

/**
 * The references of a file as "line kind name", the form the egress tests compare.
 *
 * @return list<string>
 */
function referencesOf(string $code): array
{
    return array_map(
        static fn (Reference $reference): string => $reference->line.' '.$reference->kind->value.' '.$reference->name,
        SourceFile::parse('Probe.php', $code)->references,
    );
}

it('reads function calls by their exact name, and tells them from methods, declarations and classes', function (): void {
    expect(referencesOf(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Schema\Adapter;

        use Symfony\Component\Process\Process as Runner;
        use Symfony\Component\{HttpClient\HttpClient, Process};
        use function fopen as open;
        use function Cbox\Cms\Core\Schema\file_contents;

        final class Reader
        {
            private const string NAMESPACE = 'x';

            public function copy(): void {}

            public function &file(): array
            {
                $handle = open($this->path, 'r');
                $lines = \file($this->path);
                $size = filesize($this->path) + file_exists($this->path);
                $this->pdo->exec('select 1');
                TablePrivileges::copy('a', 'b');
                $info?->openFile('r');
                $local = file_contents('x');
                $output = `curl http://metadata.internal`;
                new Runner(['curl']);
                new Process\ExecutableFinder();
                new \SplFileObject('http://metadata.internal');

                return self::NAMESPACE === 'x' ? system('id') : [];
            }
        }
        PHP))->toBe([
        '7 class Symfony\Component\Process\Process',
        '8 class Symfony\Component\HttpClient\HttpClient',
        '8 class Symfony\Component\Process',
        '14 string x',
        '20 function fopen',
        '20 string r',
        '21 function file',
        '22 function filesize',
        '22 function file_exists',
        '23 method exec',
        '24 method copy',
        '24 string a',
        '24 string b',
        '25 method openfile',
        '25 string r',
        '26 function cbox\cms\core\schema\file_contents',
        '26 string x',
        '27 function shell_exec',
        '28 string curl',
        '29 class Symfony\Component\Process\ExecutableFinder',
        '30 class SplFileObject',
        '32 string x',
        '32 function system',
        '32 string id',
    ]);
});

it('reads the classes of a group import, a trait use and a relative name, but not a closure use', function (): void {
    expect(referencesOf(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Schema\Adapter;

        use GuzzleHttp\{Client, Psr7\Request as Psr7Request};

        final class Fetcher
        {
            use \GuzzleHttp\ClientTrait, Loads {
                Loads::load insteadof ClientTrait;
            }

            public function run(): \Closure
            {
                return function () use ($x): namespace\Local {
                    return new Psr7Request('GET', 'x');
                };
            }
        }
        PHP))->toBe([
        '7 class GuzzleHttp\Client',
        '7 class GuzzleHttp\Psr7\Request',
        '11 class GuzzleHttp\ClientTrait',
        '11 class Cbox\Cms\Core\Schema\Adapter\Loads',
        '15 class Closure',
        '17 class Cbox\Cms\Core\Schema\Adapter\Local',
        '18 string GET',
        '18 string x',
    ]);
});

it('reads a string that spells a name as written, unescaped and without the leading backslash, but not a key or other text', function (): void {
    expect(referencesOf(<<<'PHP'
        <?php

        namespace Cbox\Cms\Core\Schema\Adapter;

        $a = ['file_get_contents', "\\GuzzleHttp\\Client", 'Foo\Bar::openFile', "Symfony\Component\Process\\"];
        $b = ['copy' => 1, $found['exec'], $found[0]['system'], f()['file'], $this->map['dir']];
        $c = ['the file', 'http://x', 'a b', "$x", 'Foo::', 'Foo\\\\Bar', 'x-y'];
        PHP))->toBe([
        '5 string file_get_contents',
        '5 string GuzzleHttp\Client',
        '5 string Foo\Bar::openFile',
        '5 string Symfony\Component\Process\\',
        '6 function f',
    ]);
});

it('reports every URL-capable function, socket, process and class outside the gateway', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Schema\Adapter;

        use DOMDocument;
        use Illuminate\Support\Facades\Process;
        use SplFileObject;
        use XMLReader;

        final class Fetcher
        {
            public function run(string $url, \SplFileInfo $info): void
            {
                fopen($url, 'r');
                file($url);
                readfile($url);
                copy($url, '/tmp/x');
                get_headers($url);
                hash_file('sha256', $url);
                md5_file($url);
                sha1_file($url);
                getimagesize($url);
                simplexml_load_file($url);
                parse_ini_file($url);
                pfsockopen('10.0.0.1', 80);
                stream_context_create([]);
                socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                proc_open(['curl', $url], [], $pipes);
                exec('curl '.$url);
                shell_exec('curl '.$url);
                passthru('curl '.$url);
                system('curl '.$url);
                popen('curl '.$url, 'r');
                $info->openFile('r');
                new \Symfony\Component\Process\Process(['curl', $url]);
                new \Illuminate\Process\PendingProcess();
                is_file($url);
                file_put_contents('/tmp/x', 'y');
            }
        }
        PHP);

    expect(Egress::violations([$file]))->toBe([
        'Probe.php:7: class DOMDocument',
        'Probe.php:8: class Illuminate\Support\Facades\Process',
        'Probe.php:9: class SplFileObject',
        'Probe.php:10: class XMLReader',
        'Probe.php:16: function fopen',
        'Probe.php:17: function file',
        'Probe.php:18: function readfile',
        'Probe.php:19: function copy',
        'Probe.php:20: function get_headers',
        'Probe.php:21: function hash_file',
        'Probe.php:22: function md5_file',
        'Probe.php:23: function sha1_file',
        'Probe.php:24: function getimagesize',
        'Probe.php:25: function simplexml_load_file',
        'Probe.php:26: function parse_ini_file',
        'Probe.php:27: function pfsockopen',
        'Probe.php:28: function stream_context_create',
        'Probe.php:29: function socket_create',
        'Probe.php:30: function proc_open',
        'Probe.php:31: function exec',
        'Probe.php:32: function shell_exec',
        'Probe.php:33: function passthru',
        'Probe.php:34: function system',
        'Probe.php:35: function popen',
        'Probe.php:36: method openfile',
        'Probe.php:37: class Symfony\Component\Process\Process',
        'Probe.php:38: class Illuminate\Process\PendingProcess',
        'Probe.php:40: function file_put_contents',
    ]);
});

it('reports string callables and class names in strings, the framework filesystems and the other URL-capable functions', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Schema\Adapter;

        use Illuminate\Filesystem\Filesystem;
        use Illuminate\Support\Facades\File;
        use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

        final class Fetcher
        {
            public function run(string $url, array $urls, mixed $handle): void
            {
                array_map('file_get_contents', $urls);
                call_user_func('curl_exec', $handle);
                call_user_func("\\CURL_EXEC", $handle);
                app('GuzzleHttp\\Client');
                app('\\Illuminate\\Support\\Facades\\Http');
                call_user_func('Illuminate\\Support\\Facades\\File::copy', $url, '/tmp/x');
                call_user_func([$handle, 'openFile']);
                $reader = 'readfile';
                File::copy($url, '/tmp/x');
                \Illuminate\Support\Facades\File::hash($url);
                (new \Symfony\Component\Filesystem\Filesystem())->copy($url, '/tmp/x');
                file_put_contents('ftp://host/x', 'data');
                opendir('ftp://host/');
                scandir('ftp://host/');
                dir('ftp://host/');
                new \DirectoryIterator('ftp://host/');
                new \FilesystemIterator('ftp://host/');
                new \RecursiveDirectoryIterator('ftp://host/');
                simplexml_load_string($url, options: LIBXML_NOENT);
                new \XSLTProcessor();
                new \SoapServer($url);
                mail('a@example.com', 'x', 'y');
                mb_send_mail('a@example.com', 'x', 'y');
                error_log('x', 1, 'a@example.com');
                $words = ['file' => 'the file', 'socket_timeout' => 'files', 'mode' => 'rb', 'fopen:' => $found['file'].$found[0]['exec']];
            }
        }
        PHP);

    expect(Egress::violations([$file]))->toBe([
        'Probe.php:7: class Illuminate\Filesystem\Filesystem',
        'Probe.php:8: class Illuminate\Support\Facades\File',
        'Probe.php:9: class Symfony\Component\Filesystem\Filesystem',
        'Probe.php:15: string file_get_contents',
        'Probe.php:16: string curl_exec',
        'Probe.php:17: string CURL_EXEC',
        'Probe.php:18: string GuzzleHttp\Client',
        'Probe.php:19: string Illuminate\Support\Facades\Http',
        'Probe.php:20: string Illuminate\Support\Facades\File::copy',
        'Probe.php:21: string openFile',
        'Probe.php:22: string readfile',
        'Probe.php:24: class Illuminate\Support\Facades\File',
        'Probe.php:25: class Symfony\Component\Filesystem\Filesystem',
        'Probe.php:26: function file_put_contents',
        'Probe.php:27: function opendir',
        'Probe.php:28: function scandir',
        'Probe.php:29: function dir',
        'Probe.php:30: class DirectoryIterator',
        'Probe.php:31: class FilesystemIterator',
        'Probe.php:32: class RecursiveDirectoryIterator',
        'Probe.php:33: function simplexml_load_string',
        'Probe.php:34: class XSLTProcessor',
        'Probe.php:35: class SoapServer',
        'Probe.php:36: function mail',
        'Probe.php:37: function mb_send_mail',
        'Probe.php:38: function error_log',
    ]);
});

it('lets the gateway and the allowed local uses through, and only the names they are allowed', function (): void {
    $gateway = SourceFile::parse('Gateway.php', "<?php\n\nnamespace ".Codebase::GATEWAY."\\Adapter;\n\nfinal class Client\n{\n    public function get(string \$url): void\n    {\n        fopen(\$url, 'r');\n    }\n}\n");
    $localFile = SourceFile::parse('LocalFile.php', <<<'PHP'
        <?php

        namespace Cbox\Cms\Generators\Schema\Boundary;

        use SplFileObject;

        final readonly class LocalFile
        {
            public static function contents(string $path): void
            {
                new SplFileObject($path, 'rb');
                fopen($path, 'rb');
            }
        }
        PHP);

    expect(Egress::violations([$gateway, $localFile]))->toBe(['LocalFile.php:12: function fopen']);
});

it('lets a word through only as the string it is allowed as, and the function it spells stays forbidden', function (): void {
    $command = SourceFile::parse('GenerateCommand.php', <<<'PHP'
        <?php

        namespace Cbox\Cms\Generators\Cli\Console;

        final class GenerateCommand
        {
            public function handle(int $total): string
            {
                file('/tmp/x');
                array_map('File', []);

                return $total === 1 ? 'file' : 'files';
            }
        }
        PHP);

    expect(Egress::violations([$command]))->toBe([
        'GenerateCommand.php:9: function file',
        'GenerateCommand.php:10: string File',
    ]);
});

it('reports an allowance that no code uses any more', function (): void {
    $processProbe = SourceFile::parse('ProcessToolProbe.php', "<?php\n\nnamespace Cbox\\Cms\\Core\\Doctor\\Adapter;\n\nfinal readonly class ProcessToolProbe {}\n");

    expect(Egress::unusedAllowances([$processProbe]))->toContain('Cbox\Cms\Core\Doctor\Adapter\ProcessToolProbe Symfony\Component\Process\\')
        ->and(Egress::unusedAllowances([$processProbe]))->toContain('Cbox\Cms\Generators\Cli\Console\GenerateCommand word file')
        ->and(Egress::unusedAllowances(Codebase::code()))->toBe([]);
});
