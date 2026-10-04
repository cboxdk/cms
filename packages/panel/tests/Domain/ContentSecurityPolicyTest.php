<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use InvalidArgumentException;

/*
 * The panel's Content-Security-Policy (GUARDRAILS 6, D6): scripts from the panel's origin and the
 * hashes of the page's inline scripts, never a nonce or 'strict-dynamic' for scripts; styles from
 * the origin or with the nonce; everything else pinned to the origin; frames, plugins and <base>
 * refused; reports to the panel's route; and a dev server's origin let in for scripts and
 * connections only when the page loads addons.
 */

const NONCE = 'AAAAAAAAAAAAAAAAAAAAAA==';

it('names the hashes of the inline scripts for scripts and the nonce for styles, with every directive pinned', function (): void {
    $hash = PagePolicy::hashOf('{"imports":{}}');

    expect(ContentSecurityPolicy::header(new PagePolicy(new CspNonce(NONCE), [$hash], '/cms/csp-report')))->toBe(implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'sha256-{$hash}'",
        "style-src 'self' 'nonce-".NONCE."'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "frame-src 'none'",
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        'report-uri /cms/csp-report',
        'report-to cms-csp',
    ]))
        ->and(ContentSecurityPolicy::reportingEndpoints('/cms/csp-report'))->toBe('cms-csp="/cms/csp-report"')
        ->and($hash)->toBe(base64_encode(hash('sha256', '{"imports":{}}', true)));
});

it('allows no inline script, names no nonce for scripts and reports nowhere when there is no report route', function (): void {
    $policy = ContentSecurityPolicy::header(new PagePolicy(new CspNonce(NONCE)));

    expect($policy)->toContain("script-src 'self';");
    expect($policy)->not->toContain('strict-dynamic');
    expect($policy)->not->toContain("'unsafe-");
    expect($policy)->not->toContain('report-');
    expect($policy)->toContain("style-src 'self' 'nonce-".NONCE."'");
});

it('lets a dev server in for scripts and connections, its websocket included', function (): void {
    $server = new DevServer(new AddonNamespace('tally'), 'http://localhost:5174');
    $policy = ContentSecurityPolicy::header(new PagePolicy(new CspNonce(NONCE), [], null, [$server]));

    expect($policy)->toContain("script-src 'self' http://localhost:5174;")
        ->and($policy)->toContain("connect-src 'self' http://localhost:5174 ws://localhost:5174;")
        ->and($policy)->not->toContain("style-src 'self' http");
});

it('refuses a hash that is no SHA-256 and a report path that is no path', function (): void {
    expect(fn (): PagePolicy => new PagePolicy(new CspNonce(NONCE), ['abc']))->toThrow(InvalidArgumentException::class, 'not a SHA-256')
        ->and(fn (): PagePolicy => new PagePolicy(new CspNonce(NONCE), [], 'https://other.test/report'))->toThrow(InvalidArgumentException::class, 'not a path');
});
