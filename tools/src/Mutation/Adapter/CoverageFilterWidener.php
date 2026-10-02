<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\DeclarationCoverage;
use Cbox\Cms\Tooling\Mutation\Domain\TestFilterWidth;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Support\Container;
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
 *
 * Before it widens, it gives the declarations of the mutated sources that no coverage driver can
 * cover, class constants, enum cases, properties and attributes, the tests that run the code
 * they declare values for (DeclarationCoverage), so their mutations run those tests instead of
 * counting as uncovered.
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
        $configuration = Container::getInstance()->get(ConfigurationRepository::class);
        $mutated = [];

        if ($configuration instanceof ConfigurationRepository) {
            foreach ($configuration->mergedConfiguration()->paths as $path) {
                $real = realpath($path);

                if ($real !== false && is_file($real)) {
                    $mutated[] = $real;
                }
            }
        }

        $this->widen($mutated);
    }

    /**
     * Gives the declarations of the mutated sources their tests, widens the coverage file, and
     * returns the files it widened; nothing when there is no file.
     *
     * @param  list<non-empty-string>  $mutated  absolute paths of the mutated sources
     * @return list<non-empty-string>
     */
    public function widen(array $mutated = []): array
    {
        if ($this->path === '' || ! is_file($this->path)) {
            return [];
        }

        $data = new Unserializer()->unserialize($this->path);
        $coverage = $data['codeCoverage'];
        $lineCoverage = $coverage->lineCoverage();
        $mutated = $this->asKeys($mutated, array_keys($lineCoverage));
        $declared = $mutated === [] ? $lineCoverage : DeclarationCoverage::attribute($lineCoverage, $this->contents([...array_keys($lineCoverage), ...$mutated]), $mutated);
        $widened = TestFilterWidth::widen($declared, $coverage->testIds());

        if ($widened->widenedFiles === [] && $declared === $lineCoverage) {
            return [];
        }

        $coverage->setTestIds($widened->testIds);
        $coverage->setLineCoverage($widened->lineCoverage);
        $this->write(serialize($data));

        return $widened->widenedFiles;
    }

    /**
     * The mutated sources as the coverage data names files: php-code-coverage names them relative
     * to the working directory when its paths are below it, and absolute otherwise.
     *
     * @param  list<non-empty-string>  $mutated  absolute paths
     * @param  list<non-empty-string>  $keys  the files of the coverage data
     * @return list<non-empty-string>
     */
    private function asKeys(array $mutated, array $keys): array
    {
        $known = array_flip($keys);
        $directory = getcwd();
        $prefix = $directory === false ? null : rtrim($directory, '/').'/';
        $named = [];

        foreach ($mutated as $file) {
            $relative = $prefix !== null && str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : '';
            $named[] = ! isset($known[$file]) && $relative !== '' && (isset($known[$relative]) || ! str_starts_with((string) array_key_first($known), '/')) ? $relative : $file;
        }

        return $named;
    }

    /**
     * @param  list<non-empty-string>  $files
     * @return array<non-empty-string, string>
     */
    private function contents(array $files): array
    {
        $contents = [];

        foreach ($files as $file) {
            $text = is_file($file) ? file_get_contents($file) : false;

            if ($text !== false) {
                $contents[$file] = $text;
            }
        }

        return $contents;
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
