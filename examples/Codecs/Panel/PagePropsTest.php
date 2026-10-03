<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// The props of each panel page are a document of the page's JSON Schema in
// packages/panel/resources/schemas/pages. A refusal is one of the catalog codes the schema lists
// for its field, and anything else, such as a code the page does not know, is not a valid document.

/**
 * The errors of a document against a page's schema, none when it is valid.
 *
 * @return array<array-key, mixed>
 */
function pagePropsErrors(string $schema, string $document): array
{
    $json = file_get_contents(dirname(__DIR__, 3).'/packages/panel/resources/schemas/pages/'.$schema)
        ?: throw new RuntimeException("Cannot read {$schema}.");
    $error = new CompliantValidator()->validate(json_decode($document), $json)->error();

    return $error instanceof ValidationError ? new ErrorFormatter()->format($error) : [];
}

it('accepts the props of a page', function (string $schema, string $document): void {
    expect(pagePropsErrors($schema, $document))->toBe([]);
})->with([
    'the login page after a wrong password' => ['login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":null,"refusals":{"email":null,"form":"login_rejected","password":null}}'],
    'the login page after a session expired' => ['login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":"expired","refusals":{"email":null,"form":null,"password":null}}'],
    'the page that asks for a link, just asked' => ['forgot-password.v1.json', '{"action":"/cms/forgot-password","login":"/cms/login","minutes":60,"refusals":{"email":null},"requested":true}'],
    'the reset page after a short password' => ['reset-password.v1.json', '{"action":"/cms/reset-password","forgot":"/cms/forgot-password","login":"/cms/login","refusals":{"form":null,"password":"password_too_short"},"token":null}'],
    'the start page' => ['home.v1.json', '{"logout":"/cms/logout"}'],
    'the page for a path the panel does not have' => ['not-found.v1.json', '{"home":"/cms"}'],
]);

it('refuses a refusal code the page does not know', function (): void {
    expect(pagePropsErrors('login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":null,"refusals":{"email":null,"form":"password_too_short","password":null}}'))
        ->not->toBe([]);
});
