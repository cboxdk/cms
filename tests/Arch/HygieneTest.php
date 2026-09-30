<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Egress;
use Cbox\Cms\Tests\Support\Arch\ModuleDependencies;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Arch\SourceFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

/*
 * strict_types, debug helpers, raw HTTP and facade aliases (GUARDRAILS 2.2, 3 and 9).
 */

/**
 * The namespaces of every module of cboxdk/cms, the repository's tests and the workbench.
 *
 * Pest resolves a namespace through the Composer autoloader. There is no mapping for the
 * bare Cbox\Cms prefix, so it must list each module namespace, or the modules are skipped.
 *
 * @return list<string>
 */
function codeNamespaces(): array
{
    return ['Cbox\Cms\Tests', 'Workbench\App', ...array_values(ModuleDependencies::MODULES)];
}

arch('strict_types: every class in the packages and the workbench declares strict types', function (): void {
    expect(codeNamespaces())->toHaveCount(9)->toUseStrictTypes();
});

arch('strict_types: every PHP file in the packages, tests, tools, workbench, examples and root starts with declare(strict_types=1)', function (): void {
    $files = Codebase::allPhpFiles();
    $violations = array_map(
        static fn (SourceFile $file): string => Codebase::relative($file->path),
        array_values(array_filter($files, static fn (SourceFile $file): bool => ! $file->declaresStrictTypes)),
    );

    expect(count($files))->toBeGreaterThan(20);
    Rules::none($violations, 'These files do not start with declare(strict_types=1):');
});

arch('debug functions: no dd, dump, ddd, ray or var_dump is left in the code', function (): void {
    expect(['dd', 'dump', 'ddd', 'ray', 'var_dump'])->not->toBeUsed();
});

arch('raw HTTP: no Guzzle, Http facade, HTTP client, curl_*, sockets or file_get_contents outside the gateway namespace', function (): void {
    $curl = array_values(array_filter(
        get_extension_funcs('curl') ?: [],
        static fn (string $function): bool => str_starts_with($function, 'curl_'),
    ));

    Rules::forbid(Codebase::classesOutsideGateway(), [
        'GuzzleHttp',
        Http::class,
        'Illuminate\Http\Client',
        'Symfony\Component\HttpClient',
        'Psr\Http\Client',
        'file_get_contents',
        'fsockopen',
        'stream_socket_client',
        ...$curl,
    ]);
});

arch('egress: no URL-capable file function, socket, stream context, process or XML loader outside the gateway namespace', function (): void {
    // PHP's URL wrappers fetch http:// and ftp:// through fopen, file, readfile, copy,
    // SplFileObject and the rest of Egress's lists, so they are outbound HTTP as much as Guzzle
    // is (GUARDRAILS 3). Egress::ALLOWED names the local uses and why they stay local.
    Rules::none(Egress::violations(Codebase::code()), 'Only '.Codebase::GATEWAY.' may use these, through the SSRF guard (GUARDRAILS 3):');
    Rules::none(Egress::unusedAllowances(Codebase::code()), 'These allowances in Egress::ALLOWED are no longer used; remove them:');
});

arch('facades: no global facade aliases and no real-time facades, so the layer rules see every facade', function (): void {
    $aliases = array_keys(Facade::defaultAliases()->all());
    $violations = [];

    foreach (Codebase::code() as $file) {
        foreach ($file->globalNames as $name) {
            if (in_array($name->name, $aliases, true) || str_starts_with($name->name, 'Facades\\')) {
                $violations[] = sprintf('%s uses %s.', Codebase::relative($name->location()), $name->name);
            }
        }
    }

    expect($aliases)->toContain('DB', 'Http');
    Rules::none($violations, 'Import facades by their full class name under Illuminate\Support\Facades:');
});
