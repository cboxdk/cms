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
