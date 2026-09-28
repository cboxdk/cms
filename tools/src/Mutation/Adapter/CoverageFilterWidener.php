<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\TestFilterWidth;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Support\Coverage;
use RuntimeException;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

/**
 * Widens the coverage that pest-plugin-mutate reads before it makes the mutations, so that no
 * mutation's Pest process gets a --filter argument longer than one argument may be
 * (TestFilterWidth). pest-plugin-mutate emits StartMutationGeneration in the Pest process that
 * runs the mutations, after the tests wrote their coverage to Pest's coverage file and before it
 * reads the file; this subscriber rewrites the file in place, in php-code-coverage's own
 * serialization format, and leaves it alone when no file needs widening.
 */
final readonly class CoverageFilterWidener implements StartMutationGenerationSubscriber
{
    /**
     * @param  string  $path  the coverage file; forPest() gives Pest's
     */
    public function __construct(private string $path) {}

    public static function forPest(): self
    {
        return new self(Coverage::getPath());
    }

    public function notify(StartMutationGeneration $event): void
    {
        $this->widen();
    }

    /**
     * Widens the coverage file and returns the files it widened; nothing when there is no file.
     *
     * @return list<non-empty-string>
     */
    public function widen(): array
    {
        if ($this->path === '' || ! is_file($this->path)) {
            return [];
        }

        $data = new Unserializer()->unserialize($this->path);
        $coverage = $data['codeCoverage'];
        $widened = TestFilterWidth::widen($coverage->lineCoverage(), $coverage->testIds());

        if ($widened->widenedFiles === []) {
            return [];
        }

        $coverage->setTestIds($widened->testIds);
        $coverage->setLineCoverage($widened->lineCoverage);
        $this->write(serialize($data));

        return $widened->widenedFiles;
    }

    private function write(string $serialized): void
    {
        $content = '<?php // phpunit/php-code-coverage serialization format '.Serializer::SERIALIZATION_FORMAT.PHP_EOL
            ."return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'".PHP_EOL
            .$serialized.PHP_EOL
            .'END_OF_COVERAGE_SERIALIZATION'.PHP_EOL
            .');';
        $temporary = $this->path.'.widened';

        if (file_put_contents($temporary, $content) !== strlen($content) || ! rename($temporary, $this->path)) {
            throw new RuntimeException('Cannot write the widened coverage to '.$this->path.'.');
        }

        // pest-plugin-mutate loads the file with require, so OPcache must not keep the old one
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }
}
