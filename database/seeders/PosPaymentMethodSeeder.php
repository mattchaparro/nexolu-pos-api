<?php

namespace Database\Seeders;

use App\Models\PosPaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Catalogo inicial de medios de pago del POS - los 3 defaults que ya usaba
 * Business::DEFAULT_PAYMENT_METHODS (cash/transfer/credit) mas los medios
 * mas comunes en Colombia que el legacy ya vio en negocios reales (ver
 * CONTEXT.md del legacy: nequi, bold, daviplata). SuperAdmin agrega el
 * resto desde /superadmin/pos-payment-methods segun lo pidan los negocios.
 */
class PosPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1],
            ['key' => 'transfer', 'label' => 'Transferencia', 'sort_order' => 2],
            ['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 3],
            ['key' => 'bold', 'label' => 'Bold', 'sort_order' => 4],
            ['key' => 'daviplata', 'label' => 'Daviplata', 'sort_order' => 5],
            ['key' => 'credit', 'label' => 'Fiado', 'sort_order' => 6],
        ];

        foreach ($defaults as $method) {
            PosPaymentMethod::firstOrCreate(['key' => $method['key']], $method);
        }
    }
}
