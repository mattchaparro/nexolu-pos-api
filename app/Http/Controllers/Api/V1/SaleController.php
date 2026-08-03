<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSaleRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SaleController extends Controller
{
    public function __construct(private SaleService $saleService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Sale::with('items.product')->orderByDesc('id');

        if ($request->filled('date')) {
            $query->whereDate('closed_at', $request->input('date'));
        }

        return SaleResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreSaleRequest $request): SaleResource
    {
        $sale = $this->saleService->createSale($request->user(), $request->validated());

        return new SaleResource($sale);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load('items.product'));
    }

    public function reverse(Sale $sale): Response
    {
        $this->saleService->reverseSale($sale);

        return response()->noContent();
    }
}
