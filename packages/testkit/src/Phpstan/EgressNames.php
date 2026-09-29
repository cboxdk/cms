<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The names of what reaches the network, the filesystem or another program (GUARDRAILS 3): the
 * functions, classes, container ids and methods that PHP's URL wrappers, the HTTP clients, the
 * mail, storage and notification senders and the process runners answer to.
 *
 * Two checks read these lists. The Arch suite of cboxdk/cms (tests/Support/Arch/Egress.php)
 * allows them only in the egress gateway, Cbox\Cms\Core\Egress, and in the local uses it names,
 * and HookIoRule reports every one of them in a hook (PRD 6.3). Egress.php explains each entry.
 * A class entry that ends in a backslash is a namespace and matches every class below it.
 */
#[Internal]
final class EgressNames
{
    /**
     * Functions that take a file name, which the URL wrappers also fetch, open a socket or a
     * stream context for one, or run a program.
     *
     * @var list<string>
     */
    public const array FUNCTIONS = [
        // Reading or fetching a file name.
        'bzopen',
        'copy',
        'exif_imagetype',
        'exif_read_data',
        'exif_thumbnail',
        'file',
        'file_get_contents',
        'finfo_file',
        'fopen',
        'get_headers',
        'get_meta_tags',
        'getimagesize',
        'gzfile',
        'gzopen',
        'hash_file',
        'hash_hmac_file',
        'highlight_file',
        'md5_file',
        'mime_content_type',
        'parse_ini_file',
        'php_strip_whitespace',
        'readfile',
        'readgzfile',
        'sha1_file',
        'show_source',
        'simplexml_load_file',
        'simplexml_load_string',
        // Writing, moving or removing a file name, or making, removing or listing a directory,
        // which ftp:// does remotely.
        'dir',
        'file_put_contents',
        'mkdir',
        'opendir',
        'rename',
        'rmdir',
        'scandir',
        'unlink',
        // Changing a file name's metadata, which a registered wrapper's stream_metadata() serves.
        'chgrp',
        'chmod',
        'chown',
        'touch',
        // Sending mail: error_log() with message type 1 mails its message.
        'error_log',
        'mail',
        'mb_send_mail',
        // Sockets and stream contexts.
        'fsockopen',
        'pfsockopen',
        'stream_context_create',
        'stream_context_set_default',
        'stream_socket_client',
        // Programs, which can run curl or wget.
        'exec',
        'passthru',
        'pcntl_exec',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /**
     * Families of functions: cURL, FTP, the sockets extension and GD's loaders, which take a file
     * name.
     *
     * @var list<string>
     */
    public const array FUNCTION_PREFIXES = ['curl_', 'ftp_', 'imagecreatefrom', 'socket_'];

    /**
     * Classes, and namespaces ending in a backslash, that fetch, write or list a file name or a
     * URL, send HTTP or run a program.
     *
     * @var list<string>
     */
    public const array STRING_CLASSES = [
        'DirectoryIterator',
        'DOMDocument',
        'FilesystemIterator',
        'finfo',
        'RecursiveDirectoryIterator',
        'SimpleXMLElement',
        'SoapClient',
        'SoapServer',
        'SplFileObject',
        'XMLReader',
        'XMLWriter',
        'XSLTProcessor',
        'Aws\\',
        'GuzzleHttp\\',
        'Http\Client\\',
        'Http\Discovery\\',
        'Illuminate\Contracts\Filesystem\\',
        'Illuminate\Contracts\Mail\\',
        'Illuminate\Contracts\Notifications\\',
        'Illuminate\Filesystem\\',
        'Illuminate\Http\Client\\',
        'Illuminate\Image\\',
        'Illuminate\Mail\\',
        'Illuminate\Notifications\\',
        'Illuminate\Process\\',
        'League\Flysystem\\',
        'Psr\Http\Client\\',
        'Symfony\Component\Filesystem\\',
        'Symfony\Component\HttpClient\\',
        'Symfony\Component\Mailer\\',
        'Symfony\Component\Process\\',
        'Symfony\Contracts\HttpClient\\',
    ];

    /**
     * Every class and namespace: the strings above and the facades below.
     *
     * @var list<string>
     */
    public const array CLASSES = [...self::STRING_CLASSES, ...self::FACADES];

    /** The namespace of Laravel's facades. */
    public const string FACADE_NAMESPACE = 'Illuminate\Support\Facades\\';

    /**
     * The facades of the classes above: files, HTTP, images, mail, notifications, processes and
     * storage. CLASSES holds them too. Each is written in two parts, so this class names no facade
     * for the Arch suite and Rector, which read a whole class name in a string as a use of it.
     *
     * @var list<string>
     */
    public const array FACADES = [
        self::FACADE_NAMESPACE.'File',
        self::FACADE_NAMESPACE.'Http',
        self::FACADE_NAMESPACE.'Image',
        self::FACADE_NAMESPACE.'Mail',
        self::FACADE_NAMESPACE.'Notification',
        self::FACADE_NAMESPACE.'Process',
        self::FACADE_NAMESPACE.'Storage',
    ];

    /**
     * The container ids that resolve to a class in CLASSES: the filesystems ('files' is the File
     * facade's Filesystem), the mail manager and mailer, the image manager, and 'http', which a
     * package may bind to an HTTP client. Matched exactly, as the container matches them.
     *
     * @var list<string>
     */
    public const array SERVICE_IDS = [
        'files',
        'filesystem',
        'filesystem.cloud',
        'filesystem.disk',
        'http',
        'image',
        'mail.manager',
        'mailer',
    ];

    /**
     * Methods that open a file name: SplFileInfo::openFile(), lower case.
     *
     * @var list<string>
     */
    public const array METHODS = ['openfile'];

    /**
     * Every string in the lists but the facades, which the Arch suite allows this class to spell as words: they
     * are the data of the checks, never called or resolved.
     *
     * @var list<string>
     */
    public const array WORDS = [
        ...self::FUNCTIONS,
        ...self::FUNCTION_PREFIXES,
        ...self::STRING_CLASSES,
        ...self::SERVICE_IDS,
        ...self::METHODS,
    ];
}
