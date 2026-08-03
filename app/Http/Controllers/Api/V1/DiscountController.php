<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDiscountRequest;
use App\Http\Requests\Api\V1\UpdateDiscountRequest;
use App\Http\Resources\Api\V1\DiscountResource;
use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DiscountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Discount::with('product')->latest();

        if ($request->filled('scope')) {
            $query->where('scope', $request->string('scope'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        return DiscountResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreDiscountRequest $request): DiscountResource
    {
        $discount = Discount::create($request->validated());

        return new DiscountResource($discount->load('product'));
    }

    public function show(Discount $discount): DiscountResource
    {
        return new DiscountResource($discount->load('product'));
    }

    public function update(UpdateDiscountRequest $request, Discount $discount): DiscountResource
    {
        $discount->update($request->validated());

        return new DiscountResource($discount->fresh()->load('product'));
    }

    public function destroy(Discount $discount): Response
    {
        $discount->delete();

        return response()->noContent();
    }
}
