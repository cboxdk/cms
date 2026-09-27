<?php

declare(strict_types=1);

use Examples\Contract\Doctor\UploadsDirectoryCheck;
use Illuminate\Support\Facades\Artisan;

// Adds UploadsDirectoryCheck to cms:doctor the way an application does it: the class name in
// cms.doctor.checks, which an application sets in its config/cms.php, and a contextual binding for
// the directory, which it makes in a service provider's register(). The container builds the check,
// and the doctor runs it after the core's runtime checks. The test asserts only the added check and
// the order, because the results of the core's checks depend on the host running it.

/**
 * Runs cms:doctor --json and returns the ids of the checks in the document, in the order they
 * ran, and the added check's entry.
 *
 * @param  array<string, bool>  $options
 * @return array{list<string>, array<string, mixed>}
 */
function doctorWithUploads(string $directory, array $options = []): array
{
    config(['cms.doctor.checks' => [UploadsDirectoryCheck::class]]);
    app()->when(UploadsDirectoryCheck::class)->needs('$directory')->give($directory);

    Artisan::call('cms:doctor', ['--json' => true, ...$options]);
    $document = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $checks = is_array($document) && is_array($document['checks'] ?? null) ? $document['checks'] : [];
    $ids = [];
    $added = [];

    foreach ($checks as $check) {
        if (is_array($check) && is_string($check['id'] ?? null)) {
            $ids[] = $check['id'];

            if ($check['id'] === UploadsDirectoryCheck::ID) {
                /** @var array<string, mixed> $check */
                $added = $check;
            }
        }
    }

    return [$ids, $added];
}

it('runs the added check after the core\'s runtime checks and reports it in --json', function (): void {
    [$ids, $uploads] = doctorWithUploads(sys_get_temp_dir());

    expect(array_last($ids))->toBe(UploadsDirectoryCheck::ID)
        ->and($uploads['status'])->toBe('pass')
        ->and($uploads['blocking'])->toBeFalse()
        ->and($uploads['explanation'])->toBe(sprintf('Uploads can be written to %s.', sys_get_temp_dir()));
});

it('keeps the added check before the development checks of --dev', function (): void {
    [$ids] = doctorWithUploads(sys_get_temp_dir(), ['--dev' => true]);

    expect(array_slice($ids, -4))->toBe([UploadsDirectoryCheck::ID, 'dev.node', 'dev.playwright', 'dev.chromium']);
});

it('reports the failure of the added check with its own code, cause and fix', function (): void {
    $missing = sys_get_temp_dir().'/cbox-cms-example-uploads-that-do-not-exist';

    [, $uploads] = doctorWithUploads($missing);

    expect($uploads['status'])->toBe('fail')
        ->and($uploads['failure'])->toBe('violation')
        ->and($uploads['code'])->toBe(UploadsDirectoryCheck::CODE_MISSING)
        ->and($uploads['cause'])->toBe(sprintf('%s does not exist or is not a directory.', $missing))
        ->and($uploads['fix'])->toBe(sprintf('Create %s and give the user that runs PHP write access to it.', $missing));
});
