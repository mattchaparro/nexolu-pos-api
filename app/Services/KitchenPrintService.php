<?php

namespace App\Services;

use App\Models\KitchenSentLine;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\User;
use App\Support\Printing\TicketRenderer;
use Illuminate\Support\Collection;

/**
 * Comandera fisica: la cocina recibe en papel lo nuevo de cada venta.
 *
 * Cada vez que cambia lo que la cocina tiene que hacer (se abre una cuenta,
 * se agregan o quitan platos, se confirma un pedido online, se vende un
 * domicilio) se compara lo que la venta pide hoy contra lo que la cocina ya
 * recibio impreso (kitchen_sent_lines):
 * - la primera vez sale la COMANDA completa;
 * - lo que sobra sale como ADICION;
 * - lo que falta sale como ANULADO.
 *
 * Se compara por plato (producto + variante + opciones) y no por linea de
 * la venta: editar una cuenta borra y recrea todas sus lineas
 * (OpenTabService::syncItems) y agregar un plato que ya estaba le suma
 * cantidad a la linea existente (SaleService::applyItems). Por linea, cada
 * edicion reimprimiria la cuenta entera.
 */
class KitchenPrintService
{
    public function __construct(private PrintJobService $jobs, private TicketRenderer $renderer) {}

    /**
     * Imprime en cocina la diferencia entre lo que pide la venta y lo que la
     * cocina ya tiene. No hace nada sin comandera fisica o sin impresora de
     * cocina; en ese caso tampoco registra nada, asi que el dia que se
     * conecte la impresora la siguiente comanda sale completa.
     */
    public function sync(Sale $sale, ?User $actor = null): void
    {
        $printer = $this->kitchenPrinter($sale);
        if ($printer === null) {
            return;
        }

        $current = $this->currentLines($sale);
        $sent = KitchenSentLine::query()->where('sale_id', $sale->id)->get()->keyBy('signature');
        $isFirst = $sent->isEmpty();

        $added = [];
        $removed = [];
        foreach ($current as $signature => $line) {
            $difference = $line['quantity'] - (int) ($sent[$signature]->quantity ?? 0);
            if ($difference > 0) {
                $added[] = [...$line, 'quantity' => $difference];
            }
        }
        foreach ($sent as $signature => $sentLine) {
            $difference = $sentLine->quantity - (int) ($current[$signature]['quantity'] ?? 0);
            if ($difference > 0) {
                $removed[] = [...$this->lineFromRecord($sentLine), 'quantity' => $difference];
            }
        }

        if ($removed !== []) {
            $this->jobs->enqueue($printer, PrintJob::TYPE_KITCHEN_VOID, $this->renderer->kitchen($sale, $printer, $removed, TicketRenderer::KITCHEN_VOID), $sale, null, $actor);
        }
        if ($added !== []) {
            $this->jobs->enqueue(
                $printer,
                $isFirst ? PrintJob::TYPE_KITCHEN : PrintJob::TYPE_KITCHEN_ADDITION,
                $this->renderer->kitchen($sale, $printer, $added, $isFirst ? TicketRenderer::KITCHEN_NEW : TicketRenderer::KITCHEN_ADDITION),
                $sale,
                null,
                $actor,
            );
        }

        $this->remember($sale, $current);
    }

    /** La cuenta se cancelo entera: la cocina recibe todo lo que tenia como ANULADO. */
    public function voidAll(Sale $sale, ?User $actor = null): void
    {
        $printer = $this->kitchenPrinter($sale);
        $sent = KitchenSentLine::query()->where('sale_id', $sale->id)->get();
        if ($printer === null || $sent->isEmpty()) {
            return;
        }

        $lines = $sent->map(fn (KitchenSentLine $line) => $this->lineFromRecord($line))->values()->all();

        $this->jobs->enqueue($printer, PrintJob::TYPE_KITCHEN_VOID, $this->renderer->kitchen($sale, $printer, $lines, TicketRenderer::KITCHEN_VOID), $sale, null, $actor);
        KitchenSentLine::query()->where('sale_id', $sale->id)->delete();
    }

    /**
     * Reimprime la comanda con todo lo que la venta pide hoy (se perdio el
     * papel, se mancho). No cambia lo registrado.
     */
    public function reprint(Sale $sale, ?User $actor = null): ?PrintJob
    {
        $printer = $this->kitchenPrinter($sale);
        if ($printer === null) {
            return null;
        }

        $lines = array_values($this->currentLines($sale)->all());

        return $this->jobs->enqueue(
            $printer,
            PrintJob::TYPE_KITCHEN,
            $this->renderer->kitchen($sale, $printer, $lines, TicketRenderer::KITCHEN_NEW, isReprint: true),
            $sale,
            null,
            $actor,
        );
    }

    private function kitchenPrinter(Sale $sale): ?Printer
    {
        $sale->loadMissing('business');
        if (! $this->jobs->businessPrintsKitchen($sale->business)) {
            return null;
        }

        return $this->jobs->printerFor((int) $sale->business_id, $sale->branch_id, Printer::ROLE_KITCHEN);
    }

    /**
     * Lo que la venta pide hoy, agrupado por plato.
     *
     * @return Collection<string, array{label: string, options_label: ?string, components: list<array{name: string, quantity: float}>, quantity: int}>
     */
    private function currentLines(Sale $sale): Collection
    {
        $items = $sale->items()->with(['product:id,name', 'productVariant.attributeValues', 'options', 'components'])->get();

        return $items
            ->groupBy(fn (SaleItem $item) => $this->signature($item))
            ->map(function (Collection $same) {
                /** @var SaleItem $first */
                $first = $same->first();
                $options = SaleItemOption::label($first->options);

                return [
                    'label' => $this->label($first),
                    'options_label' => $options !== '' ? $options : null,
                    'components' => $this->comboPieces($first),
                    'quantity' => (int) $same->sum(fn (SaleItem $item) => (int) round((float) $item->quantity)),
                ];
            });
    }

    /**
     * Lo que lleva un combo, por unidad: solo los productos (la hamburguesa,
     * las papas), no los insumos sueltos que tambien descuenta (el pan, la
     * salsa de la receta), que la cocina ya sabe.
     *
     * @return list<array{name: string, quantity: float}>
     */
    private function comboPieces(SaleItem $item): array
    {
        return $item->components
            ->filter(fn ($component) => $component->component_product_id !== null)
            ->map(fn ($component) => ['name' => (string) $component->name, 'quantity' => (float) $component->quantity])
            ->values()
            ->all();
    }

    /**
     * Un plato tal como quedo registrado (para anularlo).
     *
     * @return array{label: string, options_label: ?string, components: list<array{name: string, quantity: float}>, quantity: int}
     */
    private function lineFromRecord(KitchenSentLine $line): array
    {
        return [
            'label' => $line->label,
            'options_label' => $line->options_label,
            'components' => $line->components ?? [],
            'quantity' => $line->quantity,
        ];
    }

    /**
     * Producto + variante + opciones elegidas (con sus repeticiones). Dos
     * hamburguesas, una con tocineta y otra sin, son platos distintos.
     */
    private function signature(SaleItem $item): string
    {
        $options = $item->options
            ->map(fn (SaleItemOption $option) => $option->product_option_id ? 'o'.$option->product_option_id : 'n'.mb_strtolower($option->name))
            ->sort()
            ->implode(',');

        return sha1($item->product_id.'|'.($item->product_variant_id ?? '').'|'.$options);
    }

    private function label(SaleItem $item): string
    {
        $name = $item->product?->name ?: 'Producto';
        $variant = $item->productVariant?->attributeValues?->pluck('value')->join(' / ');

        return $variant ? $name.' ('.$variant.')' : $name;
    }

    /**
     * Deja registrado exactamente lo que la venta pide ahora.
     *
     * @param  Collection<string, array{label: string, options_label: ?string, quantity: int}>  $current
     */
    private function remember(Sale $sale, Collection $current): void
    {
        KitchenSentLine::query()->where('sale_id', $sale->id)->whereNotIn('signature', $current->keys()->all())->delete();

        foreach ($current as $signature => $line) {
            KitchenSentLine::query()->updateOrCreate(
                ['sale_id' => $sale->id, 'signature' => $signature],
                [
                    'label' => mb_substr($line['label'], 0, 255),
                    'options_label' => $line['options_label'],
                    'components' => $line['components'] !== [] ? $line['components'] : null,
                    'quantity' => $line['quantity'],
                ],
            );
        }
    }
}
