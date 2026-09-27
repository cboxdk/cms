<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Valkey\Boundary\ValkeySettings;
use Generator;
use InvalidArgumentException;
use Redis;
use Throwable;

/**
 * The Valkey keys of one test run.
 *
 * Every run uses the fixed test database index DATABASE and its own key prefix,
 * `cms_test_<run id>_`, so two runs at the same time never see each other's keys. One PHP process
 * is one run: forProcess() gives every test in the process the same prefix, and removes the keys
 * under it when the process exits. The harness also removes them after each test.
 *
 * Keys are removed with SCAN on the prefix and UNLINK, never with FLUSHDB, which would remove the
 * keys of the other runs in the same database. The prefix is checked, so a clean-up can never
 * match every key.
 */
#[Experimental]
final class ValkeyRun
{
    /** The database index every test run uses. Development data stays in the other indexes. */
    public const int DATABASE = 15;

    /** The start of every run prefix. `valkey-cli -n 15 --scan --pattern 'cms_test_*'` lists what runs left. */
    public const string PREFIX_STEM = 'cms_test_';

    /** The COUNT hint for SCAN. */
    public const int SCAN_COUNT = 1000;

    private const string PREFIX_PATTERN = '/\Acms_test_[a-z0-9]+_\z/';

    private static ?string $processPrefix = null;

    private static bool $cleansAtExit = false;

    public function __construct(
        public readonly ValkeySettings $settings,
        public readonly string $prefix,
    ) {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The run prefix [%s] is not of the form %s<run id>_ with a lower-case alphanumeric run id.',
                $prefix,
                self::PREFIX_STEM,
            ));
        }

        if ($settings->database !== self::DATABASE) {
            throw new InvalidArgumentException(sprintf(
                'The Redis connection [%s] uses database index %d; test runs use only index %d.',
                $settings->name,
                $settings->database,
                self::DATABASE,
            ));
        }
    }

    /**
     * The run of this PHP process. The first call picks the prefix and registers the clean-up at
     * exit; every later call returns a run with the same prefix.
     */
    public static function forProcess(ValkeySettings $settings): self
    {
        self::$processPrefix ??= self::newPrefix();
        $run = new self($settings, self::$processPrefix);

        if (! self::$cleansAtExit) {
            self::$cleansAtExit = true;
            register_shutdown_function(static function () use ($run): void {
                try {
                    $run->clean();
                } catch (Throwable) {
                    // Valkey is gone; there is nothing to clean and no test left to fail.
                }
            });
        }

        return $run;
    }

    /**
     * A fresh prefix: the stem and 12 random hex characters.
     */
    public static function newPrefix(): string
    {
        return self::PREFIX_STEM.bin2hex(random_bytes(6)).'_';
    }

    /**
     * The SCAN MATCH pattern for every key under $prefix, with the glob characters in the prefix
     * escaped.
     */
    public static function pattern(string $prefix): string
    {
        return addcslashes($prefix, '\\*?[]^').'*';
    }

    /**
     * A key of this run that lies outside every run prefix: `cms_test_<run id>`, the prefix without
     * its trailing separator. It carries the run id, so no other run writes it, and a run id has no
     * separator, so it never starts with the prefix of this or any other run and clean() never
     * removes it. A test writes it through a connection without a prefix to prove that a clean-up
     * stays inside the prefix, and removes it itself.
     */
    public function outsideKey(): string
    {
        return substr($this->prefix, 0, -1);
    }

    /**
     * A new client that puts the run prefix in front of every key. The caller closes it.
     */
    public function client(): Redis
    {
        return ValkeyConnector::connect($this->settings, $this->prefix);
    }

    /**
     * The stored names of the keys under the prefix, prefix included, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $client = ValkeyConnector::connect($this->settings);

        try {
            $keys = [];

            foreach ($this->batches($client) as $batch) {
                array_push($keys, ...$batch);
            }
        } finally {
            $client->close();
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Removes every key under the prefix with SCAN and UNLINK, and returns how many were removed.
     */
    public function clean(): int
    {
        $client = ValkeyConnector::connect($this->settings);
        $removed = 0;

        try {
            foreach ($this->batches($client) as $batch) {
                $count = $client->unlink($batch);
                $removed += is_int($count) ? $count : 0;
            }
        } finally {
            $client->close();
        }

        return $removed;
    }

    /**
     * The keys under the prefix in SCAN batches, never an empty batch. SCAN_RETRY makes phpredis
     * skip empty replies, so the loop ends when the cursor is back at 0.
     *
     * @return Generator<int, non-empty-list<string>, null, void>
     */
    private function batches(Redis $client): Generator
    {
        $client->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $pattern = self::pattern($this->prefix);
        $iterator = null;

        while (($keys = $client->scan($iterator, $pattern, self::SCAN_COUNT)) !== false) {
            $batch = array_values(array_filter($keys, is_string(...)));

            if ($batch !== []) {
                yield $batch;
            }
        }
    }
}
