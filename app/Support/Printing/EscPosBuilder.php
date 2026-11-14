<?php

namespace App\Support\Printing;

/**
 * Arma los bytes ESC/POS de un ticket termico.
 *
 * El servidor arma el ticket completo y el agente del restaurante solo lo
 * manda a la impresora: asi cambiar el formato de un ticket es un deploy del
 * API, no una actualizacion de software en cada local.
 *
 * Usa solo comandos que cualquier termica de 80/58 mm compatible con
 * ESC/POS entiende (Epson, StarPos, Jaltech, 3nStar, Xprinter...):
 * inicializar, tabla de caracteres, alineacion, negrita, tamaño doble,
 * avance, corte parcial y pulso de cajon.
 *
 * Tabla de caracteres: PC850 (ESC t 2), la que mas impresoras traen y que
 * tiene ñ, tildes, ¿ y ¡. Lo que no exista en ella se translitera.
 */
class EscPosBuilder
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private const CODEPAGE_PC850 = 2;

    /** Caracteres comunes en nombres y notas que PC850 no tiene. */
    private const TRANSLITERATIONS = [
        '“' => '"', '”' => '"', '„' => '"', '‘' => "'", '’' => "'", '´' => "'",
        '–' => '-', '—' => '-', '…' => '...', '•' => '*', '€' => 'EUR', '→' => '->',
    ];

    private string $buffer = '';

    private bool $isDoubleWidth = false;

    /**
     * @param  int  $width  caracteres por linea en fuente normal (48 en 80 mm, 32 en 58 mm)
     */
    public function __construct(private readonly int $width = 48)
    {
        $this->initialize();
    }

    public function width(): int
    {
        return $this->width;
    }

    /** Reinicia la impresora y fija la tabla de caracteres. */
    public function initialize(): static
    {
        $this->buffer .= self::ESC.'@'.self::ESC.'t'.chr(self::CODEPAGE_PC850);
        $this->isDoubleWidth = false;

        return $this;
    }

    public function alignLeft(): static
    {
        return $this->raw(self::ESC.'a'.chr(0));
    }

    public function alignCenter(): static
    {
        return $this->raw(self::ESC.'a'.chr(1));
    }

    public function alignRight(): static
    {
        return $this->raw(self::ESC.'a'.chr(2));
    }

    public function bold(bool $on = true): static
    {
        return $this->raw(self::ESC.'E'.chr($on ? 1 : 0));
    }

    /** Letra al doble de alto y ancho: la mitad de caracteres por linea. */
    public function large(bool $on = true): static
    {
        $this->isDoubleWidth = $on;

        return $this->raw(self::GS.'!'.chr($on ? 0x11 : 0x00));
    }

    /** Doble de alto, mismo ancho: se lee de lejos sin perder caracteres por linea. */
    public function tall(bool $on = true): static
    {
        $this->isDoubleWidth = false;

        return $this->raw(self::GS.'!'.chr($on ? 0x01 : 0x00));
    }

    /** Una o varias lineas de texto, partidas por palabra al ancho actual. */
    public function line(string $text = ''): static
    {
        foreach ($this->wrap($text, $this->currentWidth()) as $wrapped) {
            $this->buffer .= $this->encode($wrapped)."\n";
        }

        return $this;
    }

    /** Texto a la izquierda y valor a la derecha en la misma linea (rellena con espacios). */
    public function columns(string $left, string $right): static
    {
        $width = $this->currentWidth();
        $rightLength = mb_strlen($right);
        $leftWidth = max(1, $width - $rightLength - 1);
        $leftLines = $this->wrap($left, $leftWidth);
        $last = array_pop($leftLines);

        foreach ($leftLines as $leftLine) {
            $this->buffer .= $this->encode($leftLine)."\n";
        }

        $padding = max(1, $width - mb_strlen((string) $last) - $rightLength);
        $this->buffer .= $this->encode($last.str_repeat(' ', $padding).$right)."\n";

        return $this;
    }

    public function separator(string $char = '-'): static
    {
        $this->buffer .= str_repeat($char, $this->currentWidth())."\n";

        return $this;
    }

    public function feed(int $lines = 1): static
    {
        return $this->raw(self::ESC.'d'.chr(max(0, min(255, $lines))));
    }

    /**
     * Avanza el papel y lo corta dejando una pestaña (corte parcial): la
     * cajera separa la factura del ticket de domicilio sin que se caigan.
     * Las impresoras sin cortador ignoran el comando.
     */
    public function cut(): static
    {
        $this->large(false)->bold(false)->alignLeft();

        return $this->raw(self::GS.'V'.chr(66).chr(3));
    }

    /** Pulso al cajon conectado a la impresora (pin 2, 50 ms encendido / 500 ms apagado). */
    public function openCashDrawer(): static
    {
        return $this->raw(self::ESC.'p'.chr(0).chr(25).chr(250));
    }

    /** Agrega otro ticket ya armado (por ejemplo la factura antes del ticket de domicilio). */
    public function append(self $other): static
    {
        $this->buffer .= $other->bytes();

        return $this;
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    public function toBase64(): string
    {
        return base64_encode($this->buffer);
    }

    private function raw(string $bytes): static
    {
        $this->buffer .= $bytes;

        return $this;
    }

    private function currentWidth(): int
    {
        return $this->isDoubleWidth ? intdiv($this->width, 2) : $this->width;
    }

    /**
     * Parte por palabras; una palabra mas larga que la linea se corta. La
     * sangria inicial («    + BBQ») se conserva, tambien en las lineas que
     * salen de partir una larga.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $text = rtrim(strtr($text, self::TRANSLITERATIONS));
        if (trim($text) === '') {
            return [''];
        }

        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $paragraph) {
            preg_match('/^ */', $paragraph, $match);
            $indent = mb_substr($match[0], 0, max(0, intdiv($width, 2)));
            foreach ($this->wrapWords(trim(preg_replace('/[ \t]+/', ' ', $paragraph) ?? ''), $width - mb_strlen($indent)) as $line) {
                $lines[] = $indent.$line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wrapWords(string $paragraph, int $width): array
    {
        $width = max(1, $width);
        $lines = [];
        $current = '';
        foreach (explode(' ', $paragraph) as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }

            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                $current .= ' '.$word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        $lines[] = $current;

        return $lines;
    }

    private function encode(string $text): string
    {
        return mb_convert_encoding(strtr($text, self::TRANSLITERATIONS), 'CP850', 'UTF-8');
    }
}
