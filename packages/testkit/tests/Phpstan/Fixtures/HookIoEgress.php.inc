<?php

declare(strict_types=1);

// Every name EgressNames lists, each in a hook as HookIoRule reads it: a function given where a
// callable is taken, a class or a class in a namespace the container resolves, and a container id.

namespace Fixture\HookIoEgress {
    use Cbox\Cms\Contracts\Hooks\HookErrors;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\ValidateHook;

    final class NamesEveryEgress implements ValidateHook
    {
        public function validate(PlanView $plan): HookErrors
        {
            array_map('bzopen', []);
            array_map('copy', []);
            array_map('exif_imagetype', []);
            array_map('exif_read_data', []);
            array_map('exif_thumbnail', []);
            array_map('file', []);
            array_map('file_get_contents', []);
            array_map('finfo_file', []);
            array_map('fopen', []);
            array_map('get_headers', []);
            array_map('get_meta_tags', []);
            array_map('getimagesize', []);
            array_map('gzfile', []);
            array_map('gzopen', []);
            array_map('hash_file', []);
            array_map('hash_hmac_file', []);
            array_map('highlight_file', []);
            array_map('md5_file', []);
            array_map('mime_content_type', []);
            array_map('parse_ini_file', []);
            array_map('php_strip_whitespace', []);
            array_map('readfile', []);
            array_map('readgzfile', []);
            array_map('sha1_file', []);
            array_map('show_source', []);
            array_map('simplexml_load_file', []);
            array_map('simplexml_load_string', []);
            array_map('dir', []);
            array_map('file_put_contents', []);
            array_map('mkdir', []);
            array_map('opendir', []);
            array_map('rename', []);
            array_map('rmdir', []);
            array_map('scandir', []);
            array_map('unlink', []);
            array_map('chgrp', []);
            array_map('chmod', []);
            array_map('chown', []);
            array_map('touch', []);
            array_map('error_log', []);
            array_map('mail', []);
            array_map('mb_send_mail', []);
            array_map('fsockopen', []);
            array_map('pfsockopen', []);
            array_map('stream_context_create', []);
            array_map('stream_context_set_default', []);
            array_map('stream_socket_client', []);
            array_map('exec', []);
            array_map('passthru', []);
            array_map('pcntl_exec', []);
            array_map('popen', []);
            array_map('proc_open', []);
            array_map('shell_exec', []);
            array_map('system', []);
            array_map('curl_init', []);
            array_map('ftp_connect', []);
            array_map('imagecreatefrompng', []);
            array_map('socket_create', []);
            app('DirectoryIterator');
            app('DOMDocument');
            app('FilesystemIterator');
            app('finfo');
            app('RecursiveDirectoryIterator');
            app('SimpleXMLElement');
            app('SoapClient');
            app('SoapServer');
            app('SplFileObject');
            app('XMLReader');
            app('XMLWriter');
            app('XSLTProcessor');
            app('Aws\Thing');
            app('GuzzleHttp\Thing');
            app('Http\Client\Thing');
            app('Http\Discovery\Thing');
            app('Illuminate\Contracts\Filesystem\Thing');
            app('Illuminate\Contracts\Mail\Thing');
            app('Illuminate\Contracts\Notifications\Thing');
            app('Illuminate\Filesystem\Thing');
            app('Illuminate\Http\Client\Thing');
            app('Illuminate\Image\Thing');
            app('Illuminate\Mail\Thing');
            app('Illuminate\Notifications\Thing');
            app('Illuminate\Process\Thing');
            app('League\Flysystem\Thing');
            app('Psr\Http\Client\Thing');
            app('Symfony\Component\Filesystem\Thing');
            app('Symfony\Component\HttpClient\Thing');
            app('Symfony\Component\Mailer\Thing');
            app('Symfony\Component\Process\Thing');
            app('Symfony\Contracts\HttpClient\Thing');
            app('Illuminate\Support\Facades\File');
            app('Illuminate\Support\Facades\Http');
            app('Illuminate\Support\Facades\Image');
            app('Illuminate\Support\Facades\Mail');
            app('Illuminate\Support\Facades\Notification');
            app('Illuminate\Support\Facades\Process');
            app('Illuminate\Support\Facades\Storage');
            app('files');
            app('filesystem');
            app('filesystem.cloud');
            app('filesystem.disk');
            app('http');
            app('image');
            app('mail.manager');
            app('mailer');

            return HookErrors::none();
        }
    }
}
