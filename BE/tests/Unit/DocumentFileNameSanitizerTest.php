<?php

namespace Tests\Unit;

use App\Services\Documents\FileNameSanitizer;
use PHPUnit\Framework\TestCase;

class DocumentFileNameSanitizerTest extends TestCase
{
    private FileNameSanitizer $names;

    protected function setUp(): void
    {
        $this->names = new FileNameSanitizer;
    }

    public function test_directory_components_are_removed(): void
    {
        $this->assertSame('env', $this->names->displayName('../../.env'));
        $this->assertSame('x.pdf', $this->names->displayName('..\\x.pdf'));
        $this->assertSame('passwd', $this->names->displayName('/etc/passwd'));
        $this->assertSame('boot.ini', $this->names->displayName('C:\\Windows\\..\\boot.ini'));
    }

    public function test_control_characters_and_null_bytes_are_stripped(): void
    {
        $this->assertSame('contract.pdf', $this->names->displayName("contr\0act.pdf"));
        $this->assertSame('ab.pdf', $this->names->displayName("a\r\n\tb.pdf"));
        $this->assertSame('document', $this->names->displayName("\0\0"));
        $this->assertSame('document', $this->names->displayName('...'));
    }

    public function test_unicode_letters_are_kept_for_display_and_odd_symbols_replaced(): void
    {
        $this->assertSame('Café résumé.pdf', $this->names->displayName('Café résumé.pdf'));
        $this->assertSame('a_b_.pdf', $this->names->displayName('a<b>.pdf'));
        $this->assertSame('report.v2.pdf', $this->names->displayName('report..v2.pdf'));
    }

    public function test_long_names_keep_their_extension(): void
    {
        $name = $this->names->displayName(str_repeat('a', 400).'.pdf', 150);
        $this->assertSame(150, mb_strlen($name));
        $this->assertStringEndsWith('.pdf', $name);
    }

    public function test_extension_is_the_lowercased_last_segment(): void
    {
        $this->assertSame('pdf', $this->names->extension('A.PDF'));
        $this->assertSame('php', $this->names->extension('x.pdf.php'));
        $this->assertSame('', $this->names->extension('README'));
    }

    public function test_dangerous_segments_are_detected_anywhere_in_the_name(): void
    {
        $this->assertTrue($this->names->hasDangerousSegment('x.pdf.php'));
        $this->assertTrue($this->names->hasDangerousSegment('x.php.pdf'));
        $this->assertTrue($this->names->hasDangerousSegment('invoice.PHTML.pdf'));
        $this->assertTrue($this->names->hasDangerousSegment('logo.svg'));
        $this->assertTrue($this->names->hasDangerousSegment('page.html'));
        $this->assertTrue($this->names->hasDangerousSegment('setup.exe'));
        $this->assertTrue($this->names->hasDangerousSegment("x.php\0.pdf"));
        $this->assertFalse($this->names->hasDangerousSegment('report.v2.final.pdf'));
        $this->assertFalse($this->names->hasDangerousSegment('photo.jpeg'));
    }

    public function test_ascii_download_name_is_header_safe_and_uses_the_stored_extension(): void
    {
        $this->assertSame('Cafe resume.pdf', $this->names->asciiDownloadName('Café résumé.pdf', 'pdf'));
        $this->assertSame('evil.pdf', $this->names->asciiDownloadName('evil";.pdf', 'pdf'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 ._()-]+.pdf$/', $this->names->asciiDownloadName('日本語.pdf', 'pdf'));
        $this->assertSame('document.pdf', $this->names->asciiDownloadName('""".pdf', 'pdf'));
        $this->assertSame('scan.png.pdf', $this->names->asciiDownloadName('scan.png', 'pdf'));
        $name = $this->names->asciiDownloadName("a\"b\r\nc.pdf", 'pdf');
        $this->assertDoesNotMatchRegularExpression('/["\r\n]/', $name);
    }
}
