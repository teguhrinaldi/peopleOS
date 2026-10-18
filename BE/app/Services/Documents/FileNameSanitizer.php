<?php

namespace App\Services\Documents;

use Illuminate\Support\Str;

/**
 * Pure helpers for user-supplied file names. The result is used for DISPLAY and as the download
 * name only; storage paths are always random and never contain anything from the client.
 */
final class FileNameSanitizer
{
    /** Segments that must never appear as an extension anywhere in a name (x.php.pdf, x.pdf.php, ...). */
    public const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'scr', 'vbs', 'vbe', 'js', 'mjs', 'jse', 'wsf', 'ps1', 'psm1',
        'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'jar', 'jsp', 'asp', 'aspx', 'htaccess',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'hta', 'swf',
    ];

    /** Display-safe name: no directories, control characters or leading dots; at most $max characters. */
    public function displayName(string $name, int $max = 150): string
    {
        $name = str_replace("\0", '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        // Strip every directory component, whichever separator the client used.
        $name = str_replace('\\', '/', $name);
        $name = (string) Str::afterLast($name, '/');
        // Keep letters (any script), digits and a few punctuation marks.
        $name = preg_replace('/[^\p{L}\p{N} ._\-()\[\]]+/u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';
        $name = trim($name, " .\t");

        if ($name === '') {
            $name = 'document';
        }

        if (mb_strlen($name) > $max) {
            $ext = $this->extension($name);
            $keep = $max - ($ext !== '' ? mb_strlen($ext) + 1 : 0);
            $name = rtrim(mb_substr($name, 0, max(1, $keep)), ' .').($ext !== '' ? '.'.$ext : '');
        }

        return $name;
    }

    /** Lower-cased last extension of a (sanitised) name, '' when there is none. */
    public function extension(string $name): string
    {
        $base = (string) Str::afterLast(str_replace('\\', '/', $name), '/');
        if (! str_contains($base, '.')) {
            return '';
        }

        return strtolower((string) Str::afterLast($base, '.'));
    }

    /** True when any dot-separated segment of the RAW client name is an executable/markup extension. */
    public function hasDangerousSegment(string $rawName): bool
    {
        $base = (string) Str::afterLast(str_replace(['\\', "\0"], ['/', '.'], $rawName), '/');
        $segments = array_slice(explode('.', strtolower($base)), 1);

        foreach ($segments as $segment) {
            if (in_array(trim($segment), self::DANGEROUS_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Plain ASCII file name for the Content-Disposition header, always ending in $extension. */
    public function asciiDownloadName(string $name, string $extension): string
    {
        $base = $this->extension($name) === $extension ? Str::beforeLast($name, '.') : $name;
        $base = Str::ascii($base);
        $base = preg_replace('/[^A-Za-z0-9 ._\-()]+/', '_', $base) ?? '';
        $base = preg_replace('/_{2,}/', '_', $base) ?? '';
        $base = trim(mb_substr($base, 0, 100), ' ._');

        return ($base !== '' ? $base : 'document').'.'.$extension;
    }
}
