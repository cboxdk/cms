<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 9 for egress: every class, namespace, function, function family and container id that
 * EgressNames lists is reported in a hook, so a name taken off the list shows here.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class HookIoEgressTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_every_listed_name_in_a_hook(): void
    {
        self::assertSame($this->expected(), $this->messages());
    }

    /**
     * Each error of the testkit's rules as "line: what the hook uses".
     *
     * @return list<string>
     */
    private function messages(): array
    {
        $reported = [];

        foreach ($this->gatherAnalyserErrors([self::fixture('HookIoEgress')]) as $error) {
            if (($error->getIdentifier() ?? '') === 'cboxCms.hookIo' && preg_match('/ uses (.+)\. A hook is deterministic/', $error->getMessage(), $use) === 1) {
                $reported[] = sprintf('%d: %s', $error->getLine() ?? 0, $use[1]);
            }
        }

        sort($reported, SORT_NATURAL);

        return $reported;
    }

    /**
     * @return list<string>
     */
    private function expected(): array
    {
        return [
            '17: the function bzopen()',
            '18: the function copy()',
            '19: the function exif_imagetype()',
            '20: the function exif_read_data()',
            '21: the function exif_thumbnail()',
            '22: the function file()',
            '23: the function file_get_contents()',
            '24: the function finfo_file()',
            '25: the function fopen()',
            '26: the function get_headers()',
            '27: the function get_meta_tags()',
            '28: the function getimagesize()',
            '29: the function gzfile()',
            '30: the function gzopen()',
            '31: the function hash_file()',
            '32: the function hash_hmac_file()',
            '33: the function highlight_file()',
            '34: the function md5_file()',
            '35: the function mime_content_type()',
            '36: the function parse_ini_file()',
            '37: the function php_strip_whitespace()',
            '38: the function readfile()',
            '39: the function readgzfile()',
            '40: the function sha1_file()',
            '41: the function show_source()',
            '42: the function simplexml_load_file()',
            '43: the function simplexml_load_string()',
            '44: the function dir()',
            '45: the function file_put_contents()',
            '46: the function mkdir()',
            '47: the function opendir()',
            '48: the function rename()',
            '49: the function rmdir()',
            '50: the function scandir()',
            '51: the function unlink()',
            '52: the function chgrp()',
            '53: the function chmod()',
            '54: the function chown()',
            '55: the function touch()',
            '56: the function error_log()',
            '57: the function mail()',
            '58: the function mb_send_mail()',
            '59: the function fsockopen()',
            '60: the function pfsockopen()',
            '61: the function stream_context_create()',
            '62: the function stream_context_set_default()',
            '63: the function stream_socket_client()',
            '64: the function exec()',
            '65: the function passthru()',
            '66: the function pcntl_exec()',
            '67: the function popen()',
            '68: the function proc_open()',
            '69: the function shell_exec()',
            '70: the function system()',
            '71: the function curl_init()',
            '72: the function ftp_connect()',
            '73: the function imagecreatefrompng()',
            '74: the function socket_create()',
            '75: the class DirectoryIterator',
            '76: the class DOMDocument',
            '77: the class FilesystemIterator',
            '78: the class finfo',
            '79: the class RecursiveDirectoryIterator',
            '80: the class SimpleXMLElement',
            '81: the class SoapClient',
            '82: the class SoapServer',
            '83: the class SplFileObject',
            '84: the class XMLReader',
            '85: the class XMLWriter',
            '86: the class XSLTProcessor',
            '87: the class Aws\\Thing',
            '88: the class GuzzleHttp\\Thing',
            '89: the class Http\\Client\\Thing',
            '90: the class Http\\Discovery\\Thing',
            '91: the class Illuminate\\Contracts\\Filesystem\\Thing',
            '92: the class Illuminate\\Contracts\\Mail\\Thing',
            '93: the class Illuminate\\Contracts\\Notifications\\Thing',
            '94: the class Illuminate\\Filesystem\\Thing',
            '95: the class Illuminate\\Http\\Client\\Thing',
            '96: the class Illuminate\\Image\\Thing',
            '97: the class Illuminate\\Mail\\Thing',
            '98: the class Illuminate\\Notifications\\Thing',
            '99: the class Illuminate\\Process\\Thing',
            '100: the class League\\Flysystem\\Thing',
            '101: the class Psr\\Http\\Client\\Thing',
            '102: the class Symfony\\Component\\Filesystem\\Thing',
            '103: the class Symfony\\Component\\HttpClient\\Thing',
            '104: the class Symfony\\Component\\Mailer\\Thing',
            '105: the class Symfony\\Component\\Process\\Thing',
            '106: the class Symfony\\Contracts\\HttpClient\\Thing',
            '107: the class Illuminate\\Support\\Facades\\File',
            '108: the class Illuminate\\Support\\Facades\\Http',
            '109: the class Illuminate\\Support\\Facades\\Image',
            '110: the class Illuminate\\Support\\Facades\\Mail',
            '111: the class Illuminate\\Support\\Facades\\Notification',
            '112: the class Illuminate\\Support\\Facades\\Process',
            '113: the class Illuminate\\Support\\Facades\\Storage',
            "114: the container id 'files'",
            "115: the container id 'filesystem'",
            "116: the container id 'filesystem.cloud'",
            "117: the container id 'filesystem.disk'",
            "118: the container id 'http'",
            "119: the container id 'image'",
            "120: the container id 'mail.manager'",
            "121: the container id 'mailer'",
        ];
    }

    /**
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && str_starts_with($rule::class, 'Cbox\\Cms\\Testkit\\Phpstan\\')) {
                $rules[] = $rule;
            }
        }

        return new RegisteredRules($rules);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/hook-io.neon'];
    }
}
