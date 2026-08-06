<?php

namespace Tests\Unit\Support;

use App\Support\WhatsAppTextFormatter;
use PHPUnit\Framework\TestCase;

class WhatsAppTextFormatterTest extends TestCase
{
    public function test_converts_headings_to_bold(): void
    {
        $this->assertSame('*Titulo*', WhatsAppTextFormatter::fromMarkdown('# Titulo'));
    }

    public function test_converts_double_asterisk_bold_to_single(): void
    {
        $this->assertSame('Hola *mundo*', WhatsAppTextFormatter::fromMarkdown('Hola **mundo**'));
    }

    public function test_converts_underscore_bold_to_single_asterisk(): void
    {
        $this->assertSame('Hola *mundo*', WhatsAppTextFormatter::fromMarkdown('Hola __mundo__'));
    }

    public function test_converts_strikethrough(): void
    {
        $this->assertSame('~tachado~', WhatsAppTextFormatter::fromMarkdown('~~tachado~~'));
    }

    public function test_converts_links_to_plain_text_with_url(): void
    {
        $this->assertSame(
            'Visita Nexolú (https://nexolu.co)',
            WhatsAppTextFormatter::fromMarkdown('Visita [Nexolú](https://nexolu.co)')
        );
    }
}
