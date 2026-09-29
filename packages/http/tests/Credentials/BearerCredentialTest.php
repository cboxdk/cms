<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Credentials;

use Cbox\Cms\Http\Credentials\Boundary\BearerCredential;
use Illuminate\Http\Request;

/*
 * The credential of an HTTP request (PRD 5.16): the Bearer token of its Authorization header, none
 * without the header, and a header in another form handed on whole, so the verifier refuses it
 * instead of the call running as the anonymous principal.
 */

function bearerRequest(?string $authorization): Request
{
    $request = Request::create('/');

    if ($authorization !== null) {
        $request->headers->set(BearerCredential::HEADER, $authorization);
    }

    return $request;
}

it('reads the credential of the Authorization header', function (?string $header, ?string $credential): void {
    expect(BearerCredential::of(bearerRequest($header))?->reveal())->toBe($credential);
})->with([
    'no header' => [null, null],
    'an empty header' => ['', null],
    'a Bearer token' => ['Bearer cms_sc_token', 'cms_sc_token'],
    'the scheme in another case, and more spaces' => ['bearer   cms_sc_token', 'cms_sc_token'],
    'another scheme' => ['Basic dXNlcjpwYXNz', 'Basic dXNlcjpwYXNz'],
    'a Bearer header with two tokens' => ['Bearer one two', 'Bearer one two'],
    'a token alone' => ['cms_sc_token', 'cms_sc_token'],
]);
