<?php

namespace App\Support;

/**
 * El catalogo de bloques con los que un comerciante arma su home.
 *
 * Es una lista CERRADA a proposito. La alternativa -- dejarlo escribir HTML
 * libre con un editor visual -- se evaluo y se descarto: obliga a sanear en
 * el servidor, a un CSP defensivo porque todas las tiendas comparten
 * dominio, y sobre todo hace que el tema del comercio deje de aplicar sobre
 * lo que el escribio. Con bloques tipados el tema siempre gana, y por eso
 * "elegi tres colores y todo queda coherente" sigue siendo cierto.
 *
 * Lo que se pierde es maquetacion arbitraria: elige y ordena bloques, no
 * dibuja columnas donde quiera. Es un intercambio consciente.
 *
 * Cada bloque de la lista guardada tiene esta forma:
 *
 *     {"id": "blk_ab12", "type": "hero", "enabled": true, ...campos}
 *
 * `id` es estable y lo genera el frontend: sirve de llave al reordenar y
 * para que Vue no reuse el DOM del bloque equivocado al arrastrar.
 */
class StoreHomeBlocks
{
    public const TYPE_HERO = 'hero';

    public const TYPE_TRUST = 'trust';

    public const TYPE_STORY = 'story';

    public const TYPE_GALLERY = 'gallery';

    public const TYPE_TEXT_IMAGE = 'text_image';

    public const TYPE_FEATURED = 'featured_products';

    public const TYPE_TESTIMONIALS = 'testimonials';

    public const TYPE_FAQ = 'faq';

    public const TYPE_CTA = 'cta';

    public const TYPE_HOURS = 'hours';

    public const TYPE_BENTO = 'bento';

    public const TYPE_MARQUEE = 'marquee';

    public const TYPE_CATEGORIES = 'categories';

    public const TYPE_BEFORE_AFTER = 'before_after';

    /**
     * Cuantas veces puede repetirse cada tipo. El hero es uno solo: dos
     * portadas seguidas no es personalizacion, es una pagina rota.
     *
     * @var array<string, int>
     */
    public const MAX_PER_TYPE = [
        self::TYPE_HERO => 1,
        self::TYPE_TRUST => 1,
        self::TYPE_HOURS => 1,
    ];

    /** Tope total, para que nadie arme una home de 200 bloques. */
    public const MAX_BLOCKS = 20;

    /**
     * Reglas de validacion por tipo, sin el prefijo del indice. Las compone
     * UpdateBusinessStoreSettingsRequest.
     *
     * Todos los textos son `string` puro: nada se renderiza como HTML, asi
     * que no hace falta -- ni serviria -- permitir marcado.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function rules(): array
    {
        return [
            self::TYPE_HERO => [
                'eyebrow' => ['nullable', 'string', 'max:80'],
                'title' => ['nullable', 'string', 'max:120'],
                // Se resalta partiendo el titulo por coincidencia de texto,
                // nunca interpretando marcado (ver StoreHero.vue).
                'highlight' => ['nullable', 'string', 'max:60'],
                'subtitle' => ['nullable', 'string', 'max:300'],
                'cta_label' => ['nullable', 'string', 'max:40'],
                'image_id' => ['nullable', 'integer'],
                'image_path' => ['nullable', 'string', 'max:255'],
            ],
            self::TYPE_TRUST => [
                'items' => ['nullable', 'array', 'max:4'],
                'items.*.icon' => ['nullable', 'string', 'max:16'],
                'items.*.title' => ['required', 'string', 'max:60'],
                'items.*.text' => ['nullable', 'string', 'max:120'],
            ],
            self::TYPE_STORY => [
                'eyebrow' => ['nullable', 'string', 'max:80'],
                'title' => ['nullable', 'string', 'max:120'],
                'body' => ['nullable', 'string', 'max:1200'],
                'image_id' => ['nullable', 'integer'],
                'image_path' => ['nullable', 'string', 'max:255'],
                'stats' => ['nullable', 'array', 'max:4'],
                'stats.*.value' => ['required', 'string', 'max:20'],
                'stats.*.label' => ['required', 'string', 'max:40'],
            ],
            self::TYPE_GALLERY => [
                'title' => ['nullable', 'string', 'max:120'],
                'image_ids' => ['nullable', 'array', 'max:12'],
                'image_ids.*' => ['integer'],
            ],
            self::TYPE_TEXT_IMAGE => [
                'title' => ['nullable', 'string', 'max:120'],
                'body' => ['nullable', 'string', 'max:1200'],
                'image_id' => ['nullable', 'integer'],
                // De que lado va la imagen. Es lo unico "de maquetacion" que
                // se ofrece, y alcanza para que dos tiendas no se vean igual.
                'image_side' => ['nullable', 'in:left,right'],
                'cta_label' => ['nullable', 'string', 'max:40'],
                'cta_url' => ['nullable', 'url', 'max:255'],
            ],
            self::TYPE_FEATURED => [
                'title' => ['nullable', 'string', 'max:120'],
                // Vacio = los ultimos publicados. Asi un comerciante que no
                // quiere elegir tampoco ve un bloque vacio.
                'product_ids' => ['nullable', 'array', 'max:8'],
                'product_ids.*' => ['integer'],
            ],
            self::TYPE_TESTIMONIALS => [
                'title' => ['nullable', 'string', 'max:120'],
                'items' => ['nullable', 'array', 'max:6'],
                'items.*.quote' => ['required', 'string', 'max:300'],
                'items.*.author' => ['required', 'string', 'max:60'],
                'items.*.role' => ['nullable', 'string', 'max:60'],
            ],
            self::TYPE_FAQ => [
                'title' => ['nullable', 'string', 'max:120'],
                'items' => ['nullable', 'array', 'max:10'],
                'items.*.question' => ['required', 'string', 'max:160'],
                'items.*.answer' => ['required', 'string', 'max:600'],
            ],
            self::TYPE_CTA => [
                'title' => ['nullable', 'string', 'max:120'],
                'subtitle' => ['nullable', 'string', 'max:200'],
                'cta_label' => ['nullable', 'string', 'max:40'],
                'cta_url' => ['nullable', 'url', 'max:255'],
            ],
            self::TYPE_HOURS => [
                'title' => ['nullable', 'string', 'max:120'],
                'address' => ['nullable', 'string', 'max:200'],
                'hours' => ['nullable', 'string', 'max:200'],
                // Solo un enlace, nunca un iframe: un mapa embebido es un
                // tercero ejecutando en nuestro dominio.
                'map_url' => ['nullable', 'url', 'max:500'],
            ],

            // Reticula asimetrica de imagenes. Es la senal visual mas clara
            // de una tienda hecha en 2026, y hace que cuatro fotos regulares
            // se vean intencionales en vez de sueltas.
            self::TYPE_BENTO => [
                'title' => ['nullable', 'string', 'max:120'],
                // Cinco es el maximo con el que la reticula sigue teniendo
                // una forma reconocible; con mas se vuelve una grilla comun.
                'items' => ['nullable', 'array', 'max:5'],
                'items.*.image_id' => ['nullable', 'integer'],
                'items.*.title' => ['nullable', 'string', 'max:60'],
                'items.*.text' => ['nullable', 'string', 'max:120'],
                'items.*.url' => ['nullable', 'url', 'max:255'],
            ],

            // Cinta de texto en movimiento: "Envio gratis desde $80.000".
            self::TYPE_MARQUEE => [
                'items' => ['nullable', 'array', 'max:6'],
                'items.*.text' => ['required', 'string', 'max:80'],
                'speed' => ['nullable', 'string', 'in:slow,normal,fast'],
            ],

            // Navegar por categoria desde el home. El menos vistoso y el mas
            // util comercialmente.
            self::TYPE_CATEGORIES => [
                'title' => ['nullable', 'string', 'max:120'],
                // Vacio = todas las publicadas, igual que destacados con
                // product_ids vacio.
                'category_ids' => ['nullable', 'array', 'max:8'],
                'category_ids.*' => ['integer'],
            ],

            // Antes y despues con deslizador. Inutil para un restaurante y
            // exactamente lo que vende un spa o una barberia.
            self::TYPE_BEFORE_AFTER => [
                'title' => ['nullable', 'string', 'max:120'],
                'before_image_id' => ['nullable', 'integer'],
                'after_image_id' => ['nullable', 'integer'],
                'before_label' => ['nullable', 'string', 'max:30'],
                'after_label' => ['nullable', 'string', 'max:30'],
            ],
        ];
    }

    /**
     * Presentacion: como se coloca el bloque, no que dice.
     *
     * Aplica a TODOS los tipos y por eso vive aparte de `rules()`, que es por
     * tipo. Es lo que de verdad cambia como se ve una tienda -- el mismo
     * contenido a ancho completo y con aire respira; apretado y angosto
     * parece un formulario.
     *
     * Catalogos cerrados y no numeros libres: un comerciante eligiendo
     * pixeles de espaciado produce una pagina inconsistente. Estas tres
     * opciones estan calibradas para que cualquier combinacion se vea bien.
     *
     * @return array<string, array<int, string>>
     */
    public static function presentationRules(): array
    {
        return [
            'width' => ['nullable', 'string', 'in:contained,wide,full'],
            'spacing' => ['nullable', 'string', 'in:compact,normal,spacious'],
            // Solo la usan los bloques con imagen; guardarla en los demas es
            // inofensivo y evita reglas condicionales por tipo.
            'image_ratio' => ['nullable', 'string', 'in:auto,square,landscape,portrait'],
        ];
    }

    /** Claves que puede tener cualquier bloque, sea del tipo que sea. */
    public static function commonKeys(): array
    {
        return ['id', 'type', 'enabled', ...array_keys(self::presentationRules())];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::rules());
    }

    /**
     * Deja el bloque solo con los campos que su tipo declara, mas los
     * comunes. Es la barrera contra que alguien mande campos de mas y
     * queden guardados para siempre en el JSON.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function prune(array $block): array
    {
        $type = (string) ($block['type'] ?? '');
        $allowed = array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys(self::rules()[$type] ?? []),
        );

        return array_intersect_key($block, array_flip([...$allowed, ...self::commonKeys()]));
    }
}
