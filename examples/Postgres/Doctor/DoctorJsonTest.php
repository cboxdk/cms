<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// Runs cms:doctor --json against the services of the test environment and validates what it prints
// against doctor.v1.json from the installed cboxdk/cms, found through Composer. Decode the document
// without the associative flag, so that a JSON object stays an object for the validator. The test
// asserts no particular status: that depends on the host, for example on allow_url_fopen in its
// php.ini.

it('prints a document that doctor.v1.json accepts, and exits with its exit_code', function (array $options, bool $dev): void {
    $schema = file_get_contents(InstalledVersions::getInstallPath('cboxdk/cms').'/packages/contracts/resources/schemas/doctor.v1.json')
        ?: throw new RuntimeException('Cannot read doctor.v1.json.');

    $exitCode = Artisan::call('cms:doctor', ['--json' => true, ...$options]);
    $document = json_decode(Artisan::output(), false, 512, JSON_THROW_ON_ERROR);

    if (! $document instanceof stdClass) {
        throw new UnexpectedValueException('cms:doctor --json printed no JSON object.');
    }

    $error = new CompliantValidator()->validate($document, $schema)->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([])
        ->and($document->exit_code)->toBe($exitCode)
        ->and($document->status)->toBe(DoctorExitCode::from($exitCode)->status())
        ->and($document->dev)->toBe($dev);
})->with([
    'the runtime checks' => [[], false],
    'with the development checks of --dev' => [['--dev' => true], true],
]);
