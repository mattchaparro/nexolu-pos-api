<?php

namespace Tests\Unit\Support;

use App\Support\Printing\EscPosBuilder;
use PHPUnit\Framework\TestCase;

class EscPosBuilderTest extends TestCase
{
    public function test_it_starts_by_resetting_the_printer_and_selecting_pc850(): void
    {
        $bytes = (new EscPosBuilder)->bytes();

        $this->assertSame("\x1B@\x1Bt\x02", $bytes);
    }

    public function test_spanish_characters_are_encoded_in_pc850(): void
    {
        $bytes = (new EscPosBuilder)->line('Ñandú ¿sí?')->bytes();

        // Ñ=A5, a, n, d, ú=A3, espacio, ¿=A8, s, í=A1, ?
        $this->assertStringContainsString("\xA5and\xA3 \xA8s\xA1?\n", $bytes);
    }

    public function test_characters_missing_from_pc850_are_transliterated(): void
    {
        $bytes = (new EscPosBuilder)->line('“Sin cebolla” — rápido…')->bytes();

        $this->assertStringContainsString('"Sin cebolla" - r'."\xA0".'pido...', $bytes);
    }

    public function test_columns_push_the_value_to_the_right_edge(): void
    {
        $bytes = (new EscPosBuilder(32))->columns('TOTAL', '$24.000')->bytes();

        $this->assertStringContainsString('TOTAL'.str_repeat(' ', 32 - 5 - 7).'$24.000'."\n", $bytes);
    }

    public function test_long_text_wraps_by_words_to_the_paper_width(): void
    {
        $bytes = (new EscPosBuilder(32))->line('Hamburguesa doble con tocineta y queso cheddar extra')->bytes();

        $this->assertStringContainsString("Hamburguesa doble con tocineta y\nqueso cheddar extra\n", $bytes);
    }

    public function test_leading_indentation_is_kept_on_every_wrapped_line(): void
    {
        $bytes = (new EscPosBuilder(20))->line('    + Salsa de la casa con ajo')->columns('  2 x $9.000', '$18.000')->bytes();

        $this->assertStringContainsString("    + Salsa de la\n    casa con ajo\n", $bytes);
        $this->assertStringContainsString('  2 x $9.000 $18.000'."\n", $bytes);
    }

    public function test_large_text_uses_half_the_characters_per_line(): void
    {
        $bytes = (new EscPosBuilder(32))->large()->separator()->large(false)->separator()->bytes();

        $this->assertStringContainsString("\x1D!\x11".str_repeat('-', 16)."\n\x1D!\x00".str_repeat('-', 32)."\n", $bytes);
    }

    public function test_cut_and_cash_drawer_commands(): void
    {
        $bytes = (new EscPosBuilder)->openCashDrawer()->cut()->bytes();

        $this->assertStringContainsString("\x1Bp\x00\x19\xFA", $bytes);
        $this->assertStringEndsWith("\x1DV\x42\x03", $bytes);
    }

    public function test_append_joins_two_tickets_in_order(): void
    {
        $receipt = (new EscPosBuilder)->line('FACTURA')->cut();
        $delivery = (new EscPosBuilder)->line('DOMICILIO')->cut();

        $bytes = $receipt->append($delivery)->bytes();

        $this->assertLessThan(strpos($bytes, 'DOMICILIO'), strpos($bytes, 'FACTURA'));
        $this->assertSame(2, substr_count($bytes, "\x1DV\x42\x03"));
    }
}
