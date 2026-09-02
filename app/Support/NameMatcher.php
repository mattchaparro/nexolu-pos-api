<?php

namespace App\Support;

/**
 * Busca un nombre dicho por una persona dentro de un catalogo.
 *
 * Existe porque la comparacion por subcadena no alcanzaba. El caso que la
 * rompio en produccion (legacy): el usuario pidio "15 gaseosas de manzana" y
 * el catalogo tenia "Gaseosa de manzana - 500 ml". Ninguna de las dos cadenas
 * contiene a la otra -- sobra la "s" del plural y sobra el "500 ml" -- asi que
 * no habia coincidencia y el asistente le pedia el nombre exacto, que es justo
 * el trabajo que la herramienta venia a ahorrarle.
 *
 * El criterio es por palabras: un candidato sirve si TODAS las palabras
 * significativas de lo que dijo el usuario aparecen en su nombre. "gaseosa
 * manzana" esta contenido en "gaseosa manzana 500 ml", asi que coincide; pero
 * "gaseosa naranja" no, porque "naranja" no aparece.
 *
 * Sigue sin adivinar entre varios: si quedan dos candidatos, quien llama
 * pregunta. Lo que cambia es cuantas veces hace falta preguntar.
 */
final class NameMatcher
{
    /**
     * Palabras que no aportan a la busqueda.
     *
     * Sin esto "gaseosa de manzana" exigiria que el producto tuviera un "de",
     * y "Gaseosa manzana 500ml" quedaria fuera por una preposicion.
     */
    private const STOP_WORDS = ['de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'y', 'con', 'para', 'al'];

    /**
     * Candidatos que coinciden con lo buscado, del mas al menos especifico.
     *
     * @template T
     *
     * @param  iterable<T>  $candidates
     * @param  callable(T): string  $nameOf
     * @return list<T>
     */
    public static function filter(iterable $candidates, string $needle, callable $nameOf): array
    {
        $needleWords = self::words($needle);

        if ($needleWords === []) {
            return [];
        }

        $matches = [];

        foreach ($candidates as $candidate) {
            $candidateWords = self::words($nameOf($candidate));

            if ($candidateWords === []) {
                continue;
            }

            // Todas las palabras buscadas tienen que estar. Al reves no: el
            // nombre del catalogo puede traer detalles que el usuario no dijo
            // ("500 ml", "x12", la marca), y exigirselos seria pedirle que
            // recite la ficha del producto.
            if (array_diff($needleWords, $candidateWords) !== []) {
                continue;
            }

            $matches[] = [
                'item' => $candidate,
                // Menos palabras sobrantes = nombre mas ajustado a lo pedido.
                // Con "gaseosa" empatan "Gaseosa" y "Gaseosa de manzana 500 ml",
                // y la primera es la que el usuario nombro exactamente.
                'extra' => count(array_diff($candidateWords, $needleWords)),
            ];
        }

        usort($matches, fn ($a, $b) => $a['extra'] <=> $b['extra']);

        return array_column($matches, 'item');
    }

    /**
     * Coincidencia exacta: mismas palabras significativas, en cualquier orden.
     *
     * Resuelve un empate antes de preguntar. Si el usuario dijo "gaseosa" y
     * existe un producto llamado exactamente "Gaseosa", ese gana aunque haya
     * otros que empiecen igual.
     *
     * @template T
     *
     * @param  iterable<T>  $candidates
     * @param  callable(T): string  $nameOf
     * @return list<T>
     */
    public static function exact(iterable $candidates, string $needle, callable $nameOf): array
    {
        $needleWords = self::words($needle);
        sort($needleWords);

        $out = [];

        foreach ($candidates as $candidate) {
            $words = self::words($nameOf($candidate));
            sort($words);

            if ($words === $needleWords && $words !== []) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Palabras significativas de un texto, normalizadas y en singular.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = ComparableName::of($text);

        if ($normalized === '') {
            return [];
        }

        $words = array_filter(
            array_map([self::class, 'singular'], explode(' ', $normalized)),
            fn (string $word) => $word !== '' && ! in_array($word, self::STOP_WORDS, true)
        );

        return array_values(array_unique($words));
    }

    /**
     * Singular aproximado en espanol.
     *
     * No pretende ser correcto en todos los casos: solo tiene que hacer que
     * "gaseosas" y "gaseosa" caigan en la misma forma. Aplicarlo a los dos
     * lados de la comparacion hace que un error de singularizacion sea
     * inofensivo mientras sea consistente ("lapices" y "lapiz" no coinciden,
     * pero "lapices" y "lapices" si).
     *
     * Las palabras cortas se dejan como estan: "mes" o "gas" no son plurales,
     * y recortarlas produciria coincidencias falsas.
     */
    public static function singular(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }

        // "lapices" -> "lapiz", "luces" -> "luz"
        if (str_ends_with($word, 'ces') && mb_strlen($word) > 4) {
            return mb_substr($word, 0, -3).'z';
        }

        // "panes" -> "pan", "melones" -> "melon"
        if (str_ends_with($word, 'es') && mb_strlen($word) > 4) {
            return mb_substr($word, 0, -2);
        }

        if (str_ends_with($word, 's')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }
}
