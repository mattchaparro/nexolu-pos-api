<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StockMovementReasonResource;
use App\Models\StockMovementReason;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockMovementReasonController extends Controller
{
    /**
     * Lista de motivos de movimiento de stock (globales, business_id null -
     * ver StockMovementReasonSeeder) para el selector de "Ajustar stock" del
     * frontend. Sin paginar: son ~10 filas fijas, igual que /product-categories.
     */
    public function index(): AnonymousResourceCollection
    {
        return StockMovementReasonResource::collection(
            StockMovementReason::whereNull('business_id')->orderBy('id')->get()
        );
    }
}
