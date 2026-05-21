<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Util;

use Duyler\HttpServer\Util\MimeTypeMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MimeTypeMapTest extends TestCase
{
    #[Test]
    public function returns_html_from_extension(): void
    {
        self::assertSame('text/html', MimeTypeMap::getFromExtension('html'));
    }

    #[Test]
    public function returns_html_from_htm_extension(): void
    {
        self::assertSame('text/html', MimeTypeMap::getFromExtension('htm'));
    }

    #[Test]
    public function returns_css_from_extension(): void
    {
        self::assertSame('text/css', MimeTypeMap::getFromExtension('css'));
    }

    #[Test]
    public function returns_javascript_from_extension(): void
    {
        self::assertSame('application/javascript', MimeTypeMap::getFromExtension('js'));
    }

    #[Test]
    public function returns_json_from_extension(): void
    {
        self::assertSame('application/json', MimeTypeMap::getFromExtension('json'));
    }

    #[Test]
    public function returns_xml_from_extension(): void
    {
        self::assertSame('application/xml', MimeTypeMap::getFromExtension('xml'));
    }

    #[Test]
    public function returns_plain_text_from_extension(): void
    {
        self::assertSame('text/plain', MimeTypeMap::getFromExtension('txt'));
    }

    #[Test]
    public function returns_jpeg_from_jpg_extension(): void
    {
        self::assertSame('image/jpeg', MimeTypeMap::getFromExtension('jpg'));
    }

    #[Test]
    public function returns_jpeg_from_jpeg_extension(): void
    {
        self::assertSame('image/jpeg', MimeTypeMap::getFromExtension('jpeg'));
    }

    #[Test]
    public function returns_png_from_extension(): void
    {
        self::assertSame('image/png', MimeTypeMap::getFromExtension('png'));
    }

    #[Test]
    public function returns_gif_from_extension(): void
    {
        self::assertSame('image/gif', MimeTypeMap::getFromExtension('gif'));
    }

    #[Test]
    public function returns_svg_from_extension(): void
    {
        self::assertSame('image/svg+xml', MimeTypeMap::getFromExtension('svg'));
    }

    #[Test]
    public function returns_ico_from_extension(): void
    {
        self::assertSame('image/x-icon', MimeTypeMap::getFromExtension('ico'));
    }

    #[Test]
    public function returns_pdf_from_extension(): void
    {
        self::assertSame('application/pdf', MimeTypeMap::getFromExtension('pdf'));
    }

    #[Test]
    public function returns_zip_from_extension(): void
    {
        self::assertSame('application/zip', MimeTypeMap::getFromExtension('zip'));
    }

    #[Test]
    public function returns_woff_from_extension(): void
    {
        self::assertSame('font/woff', MimeTypeMap::getFromExtension('woff'));
    }

    #[Test]
    public function returns_woff2_from_extension(): void
    {
        self::assertSame('font/woff2', MimeTypeMap::getFromExtension('woff2'));
    }

    #[Test]
    public function returns_ttf_from_extension(): void
    {
        self::assertSame('font/ttf', MimeTypeMap::getFromExtension('ttf'));
    }

    #[Test]
    public function returns_otf_from_extension(): void
    {
        self::assertSame('font/otf', MimeTypeMap::getFromExtension('otf'));
    }

    #[Test]
    public function returns_mp4_from_extension(): void
    {
        self::assertSame('video/mp4', MimeTypeMap::getFromExtension('mp4'));
    }

    #[Test]
    public function returns_mp3_from_extension(): void
    {
        self::assertSame('audio/mpeg', MimeTypeMap::getFromExtension('mp3'));
    }

    #[Test]
    public function returns_octet_stream_for_unknown_extension(): void
    {
        self::assertSame('application/octet-stream', MimeTypeMap::getFromExtension('xyz'));
    }

    #[Test]
    public function handles_uppercase_extension(): void
    {
        self::assertSame('text/html', MimeTypeMap::getFromExtension('HTML'));
    }

    #[Test]
    public function handles_mixed_case_extension(): void
    {
        self::assertSame('image/jpeg', MimeTypeMap::getFromExtension('JpG'));
    }

    #[Test]
    public function returns_octet_stream_for_empty_extension(): void
    {
        self::assertSame('application/octet-stream', MimeTypeMap::getFromExtension(''));
    }

    #[Test]
    public function extracts_mime_from_file_path(): void
    {
        self::assertSame('text/html', MimeTypeMap::getFromFilePath('/var/www/index.html'));
    }

    #[Test]
    public function extracts_mime_from_file_path_with_multiple_dots(): void
    {
        self::assertSame('application/javascript', MimeTypeMap::getFromFilePath('/var/www/bundle.min.js'));
    }

    #[Test]
    public function extracts_mime_from_file_path_without_directory(): void
    {
        self::assertSame('application/json', MimeTypeMap::getFromFilePath('data.json'));
    }

    #[Test]
    public function returns_octet_stream_for_file_without_extension(): void
    {
        self::assertSame('application/octet-stream', MimeTypeMap::getFromFilePath('/var/www/README'));
    }

    #[Test]
    public function returns_octet_stream_for_hidden_file(): void
    {
        self::assertSame('application/octet-stream', MimeTypeMap::getFromFilePath('/var/www/.htaccess'));
    }

    #[Test]
    public function handles_uppercase_file_path(): void
    {
        self::assertSame('text/css', MimeTypeMap::getFromFilePath('/var/www/STYLES.CSS'));
    }
}
