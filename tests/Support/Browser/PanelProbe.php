<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Closure;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use JsonException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Puts the probe host of PanelModules on a real panel page (PRD 13.4): when the test application
 * has answered the page, it adds, with the nonce of the page,
 *
 * - first in <head>, a script that records every Content-Security-Policy violation the document
 *   reports, everything written to console.error and console.warn, every error and unhandled
 *   rejection, and that stands in for React DevTools, recording each React renderer that starts,
 *   which is how the test sees how many React DOMs the page runs and which React each uses;
 * - last in <head>, the probe's settings as JSON and the probe host's module.
 *
 * The page keeps everything else, its import map included. Its policy names the panel's origin and
 * the hashes of the page's inline scripts (D6), so the probe sends the page's policy again with
 * the hash of the recorder among them (policyOf()), unless the test gives another policy. These
 * listeners work in Chromium, Firefox and WebKit alike, where the reporting API PanelPage reads is
 * Chromium's.
 */
final class PanelProbe
{
    private const string RECORDER = <<<'JS'
        (() => {
            const log = (window.cmsProbeLog = { violations: [], errors: [] });
            document.addEventListener('securitypolicyviolation', (event) => log.violations.push(`${event.effectiveDirective} blocked ${event.blockedURI || 'an inline resource'}`));
            for (const level of ['error', 'warn']) {
                const original = console[level];
                console[level] = (...args) => { log.errors.push(`${level}: ${args.map(String).join(' ')}`); original.apply(console, args); };
            }
            window.addEventListener('error', (event) => log.errors.push(`error: ${event.message}`));
            window.addEventListener('unhandledrejection', (event) => log.errors.push(`unhandled rejection: ${String(event.reason)}`));
            const renderers = new Map();
            window.__REACT_DEVTOOLS_GLOBAL_HOOK__ = {
                renderers,
                supportsFiber: true,
                isDisabled: false,
                inject(renderer) { renderers.set(renderers.size + 1, renderer); return renderers.size; },
                checkDCE() {},
                onScheduleFiberRoot() {},
                onCommitFiberRoot() {},
                onCommitFiberUnmount() {},
                onPostCommitFiberRoot() {},
            };
        })();
        JS;

    /** What the probe host wrote, once it is done, and what the recorder saw; waits up to 10 seconds. */
    private const string RESULT = <<<'JS'
        () => new Promise((resolve) => {
            const started = Date.now();
            const poll = () => {
                if (window.cmsProbe?.done === true || Date.now() - started > 10000) {
                    resolve({ probe: window.cmsProbe ?? null, log: window.cmsProbeLog ?? null });
                } else {
                    setTimeout(poll, 25);
                }
            };
            poll();
        })
        JS;

    /**
     * Puts the probe on the panel page at the path.
     *
     * @param  array{crossOrigin?: string|null, kit?: bool}  $settings  a path the host imports from the other loopback origin, and whether it mounts the kit probe
     * @param  (Closure(string $html, string $nonce): string)|null  $policy  the policy the page gets instead of its own, from its final HTML and nonce
     */
    public static function onPage(string $path, array $settings = [], ?Closure $policy = null): void
    {
        $config = [
            'addons' => PanelModules::PREFIX,
            'crossOrigin' => isset($settings['crossOrigin']) ? PanelModules::PATH.$settings['crossOrigin'] : null,
            'kit' => ($settings['kit'] ?? false) ? PanelModules::PATH.'kit-probe.js' : null,
        ];

        Event::listen(RequestHandled::class, static function (RequestHandled $handled) use ($path, $config, $policy): void {
            if ('/'.ltrim($handled->request->path(), '/') !== $path) {
                return;
            }

            $nonce = SendContentSecurityPolicy::nonceOf($handled->request)->value;
            $html = (string) $handled->response->getContent();
            $recorder = '<script nonce="'.$nonce.'">'.self::RECORDER.'</script>';
            $host = '<script type="application/json" id="cms-probe">'.json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>'
                .'<script type="module" src="'.PanelModules::PATH.'host.js" nonce="'.$nonce.'"></script>';
            $html = str_replace(['<head>', '</head>'], ["<head>\n    {$recorder}", "    {$host}\n</head>"], $html);

            $handled->response->setContent($html);
            $handled->response->headers->set(ContentSecurityPolicy::HEADER, $policy instanceof Closure ? $policy($html, $nonce) : self::policyOf($html, $nonce, (string) $handled->response->headers->get(ContentSecurityPolicy::HEADER)));
        });
    }

    /**
     * The panel's policy for the final HTML: the hashes of every inline script in it, and the
     * report path of the policy the page was sent with.
     */
    public static function policyOf(string $html, string $nonce, string $sent): string
    {
        $reportPath = preg_match('/report-uri (\S+)/', $sent, $match) === 1 ? $match[1] : null;

        return ContentSecurityPolicy::header(new PagePolicy(new CspNonce($nonce), self::inlineScriptHashes($html), $reportPath));
    }

    /**
     * The SHA-256, in base64, of each inline script of the page, its import map included.
     *
     * @return list<string>
     */
    public static function inlineScriptHashes(string $html): array
    {
        preg_match_all('~<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>(.*?)</script>~s', $html, $scripts);

        return array_map(PagePolicy::hashOf(...), $scripts[1]);
    }

    /**
     * What the probe host wrote and what the recorder saw.
     *
     * @return array{probe: array<string, mixed>, log: array{violations: list<string>, errors: list<string>}}
     *
     * @throws JsonException
     */
    public static function result(PendingAwaitablePage|AwaitableWebpage|Webpage $page): array
    {
        $result = $page->script(self::RESULT);
        $probe = is_array($result) ? ($result['probe'] ?? null) : null;
        $log = is_array($result) ? ($result['log'] ?? null) : null;

        if (! is_array($probe) || ($probe['done'] ?? false) !== true) {
            throw new RuntimeException('The probe host did not finish within 10 seconds: '.json_encode($result, JSON_THROW_ON_ERROR));
        }

        if (! is_array($log) || ! is_array($log['violations'] ?? null) || ! is_array($log['errors'] ?? null)) {
            throw new RuntimeException('The probe recorder did not run: '.json_encode($result, JSON_THROW_ON_ERROR));
        }

        /** @var array<string, mixed> $probe */
        /** @var array{violations: list<string>, errors: list<string>} $log */
        return ['probe' => $probe, 'log' => $log];
    }

    /**
     * The value of a script expression once it is truthy, or its last value after 5 seconds: what
     * React and React Aria do after an event, such as moving the focus when a dialog closes, may
     * take a frame or two, and the browsers differ in how many.
     */
    public static function eventually(PendingAwaitablePage|AwaitableWebpage|Webpage $page, string $expression): mixed
    {
        return $page->script(<<<JS
            () => new Promise((resolve) => {
                const started = Date.now();
                const poll = () => {
                    const value = ({$expression});
                    if (value || Date.now() - started > 5000) {
                        resolve(value);
                    } else {
                        requestAnimationFrame(poll);
                    }
                };
                poll();
            })
            JS);
    }

    /**
     * Asserts that the page reported no policy violation and wrote no error or warning.
     *
     * @param  array{violations: list<string>, errors: list<string>}  $log
     */
    public static function assertQuiet(array $log): void
    {
        Assert::assertSame([], $log['violations'], 'The page broke its Content-Security-Policy.');
        Assert::assertSame([], $log['errors'], 'The page wrote errors or warnings.');
    }
}
