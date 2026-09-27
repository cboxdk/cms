<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Generators\Tests\Schema\Fakes\RecordingUrlWrapper;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\RecordingStreamWrapper;
use Symfony\Component\Process\Process;

/*
 * The tests of the local file readers and writers (GUARDRAILS 3) register a stream wrapper that
 * stands in for http:// and ftp:// and expect no call to it. That only means something when PHP
 * would call the wrapper, also with allow_url_fopen off, as the php container of compose.yaml
 * runs (docker/php/conf.d/cms.ini). PHP refuses a wrapper registered with STREAM_IS_URL there
 * before calling it, so each probe is started here in its own PHP with either setting.
 */

it('reaches the recording wrapper with allow_url_fopen on and off', function (string $wrapper, string $url, string $setting): void {
    $script = sprintf(
        'require %s; %s::register(); is_file(%s); echo json_encode(%s::$calls);',
        var_export(Phpstan::root().'/vendor/autoload.php', true),
        $wrapper,
        var_export($url, true),
        $wrapper,
    );
    $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen='.$setting, '-r', $script], Phpstan::root());
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['url_stat '.$url]);
})->with([
    'the writers\' wrapper' => [RecordingStreamWrapper::class, RecordingStreamWrapper::url('/cache/actions.php')],
    'LocalFile\'s wrapper' => [RecordingUrlWrapper::class, RecordingUrlWrapper::SCHEME.'://metadata.internal/schema.yaml'],
])->with(['off' => '0', 'on' => '1']);
