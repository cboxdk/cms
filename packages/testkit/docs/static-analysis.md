# Static analysis for addons

`cboxdk/cms-testkit` ships the PHPStan configuration the kernel is analysed with (GUARDRAILS 1, 2.2 and 10): level 10 with Larastan, stricter checks than level 10 alone, and the kernel's own rules. An addon includes it from its `phpstan.neon` and adds only its own paths:

- `includes:` with the entry `vendor/cboxdk/cms-testkit/config/phpstan.neon`
- `parameters.paths:` with the addon's `src` and `tests`

The addon does not lower the level, add a baseline or add `ignoreErrors`. The rules report errors under identifiers that start with `cboxCms.`, and most of them are non-ignorable: no comment and no `ignoreErrors` entry hides them.

## Internal API

The kernel marks each public class, interface, trait and enum `#[Stable]`, `#[Experimental]` or `#[Internal]` (GUARDRAILS 2.3). An addon builds on the stable and experimental API. `#[Internal]` API can change or disappear in any release, so the shared configuration reports every use of it outside the `Cbox\Cms` namespace as `cboxCms.internalUse`. That covers a class marked `#[Internal]` in `new`, static calls, constants, `::class`, `instanceof`, `catch`, `extends`, `implements`, trait use, attributes, native and PHPDoc types, and calls of its methods, as well as a method or class constant marked `#[Internal]` on a stable class.

When an addon has no way around an internal symbol for now, it can hide the one use explicitly, with a comment that names the identifier on the line of the use or on the line above it:

- `// @phpstan-ignore cboxCms.internalUse (the reason)`

The comment belongs in a `Boundary` or `Adapter` namespace of the addon, because the shared configuration reports any `@phpstan-ignore` comment elsewhere as `cboxCms.phpstanIgnore`. GUARDRAILS 2.2 asks that such a suppression is precise, gives its reason in the parentheses and says when it goes away. Each time the comment names the identifier it hides one use on its line, so a line with two uses names it twice: `@phpstan-ignore cboxCms.internalUse, cboxCms.internalUse`. A comment that hides no use is reported as unmatched.

Nothing else hides the use:

- `@phpstan-ignore-line` and `@phpstan-ignore-next-line`, which hide every error on a line;
- `@phpstan-ignore` without an identifier, or with other identifiers only;
- an `ignoreErrors` entry of any kind, by message pattern, raw message, identifier or path, and so a baseline.

PHPStan's restricted usage rules find the uses, and the testkit reports them again after the analysis, non-ignorable unless a comment on the line names `cboxCms.internalUse`. The test below analyses an addon's adapter with the shared configuration, first with an explicit comment, then with one that hides every error on the line, and then with none.

<!-- example: examples/Unit/StaticAnalysis/InternalUseIgnoreTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\StaticAnalysis;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An addon's adapter calls LocalPath::namesStreamWrapper(), a method of an #[Internal] class of
 * the kernel, and PHPStan analyses it with the shared configuration of cboxdk/cms-testkit, as the
 * addon's CI does. Only an ignore comment that names cboxCms.internalUse lets the analysis pass.
 */
final class InternalUseIgnoreTest extends TestCase
{
    #[Test]
    public function an_ignore_comment_that_names_the_identifier_hides_the_use(): void
    {
        $identifiers = $this->analyse('// @phpstan-ignore cboxCms.internalUse (no stable API checks for a stream wrapper yet)');

        self::assertSame([], $identifiers);
    }

    #[Test]
    public function a_comment_that_ignores_every_error_on_the_line_hides_nothing(): void
    {
        $identifiers = $this->analyse('// @phpstan-ignore-next-line');

        self::assertContains('cboxCms.internalUse', $identifiers);
    }

    #[Test]
    public function without_a_comment_the_use_is_reported(): void
    {
        $identifiers = $this->analyse('// LocalPath is internal to the kernel.');

        self::assertSame(['cboxCms.internalUse'], $identifiers);
    }

    /**
     * The identifiers of the errors PHPStan reports on the adapter with the comment on the line
     * above the call.
     *
     * @return list<string>
     *
     * @throws JsonException
     */
    private function analyse(string $comment): array
    {
        $directory = sys_get_temp_dir().'/uploads-adapter-'.bin2hex(random_bytes(4));
        $adapter = $directory.'/UploadDirectory.php';
        mkdir($directory);

        try {
            file_put_contents($adapter, <<<PHP
                <?php

                declare(strict_types=1);

                namespace Acme\\Uploads\\Adapter;

                use Cbox\\Cms\\Contracts\\Storage\\LocalPath;
                use InvalidArgumentException;

                final readonly class UploadDirectory
                {
                    public function __construct(public string \$root)
                    {
                        {$comment}
                        if (LocalPath::namesStreamWrapper(\$root)) {
                            throw new InvalidArgumentException('Uploads are kept on the local disk.');
                        }
                    }
                }
                PHP);

            // The addon's phpstan.neon includes vendor/cboxdk/cms-testkit/config/phpstan.neon.
            $process = new Process([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', $adapter], dirname(__DIR__, 3), timeout: 300);
            $process->run();

            return $this->identifiers($process->getOutput());
        } finally {
            if (is_file($adapter)) {
                unlink($adapter);
            }

            rmdir($directory);
        }
    }

    /**
     * @return list<string>
     *
     * @throws JsonException
     */
    private function identifiers(string $json): array
    {
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $files = is_array($report) ? ($report['files'] ?? null) : null;

        if (! is_array($files)) {
            throw new RuntimeException("PHPStan printed no report: {$json}");
        }

        $identifiers = [];

        foreach ($files as $file) {
            foreach (is_array($file) && is_array($file['messages'] ?? null) ? $file['messages'] : [] as $message) {
                if (is_array($message) && is_string($message['identifier'] ?? null)) {
                    $identifiers[] = $message['identifier'];
                }
            }
        }

        sort($identifiers);

        return $identifiers;
    }
}
```
