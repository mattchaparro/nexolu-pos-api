<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIngredientRequest;
use App\Http\Requests\Api\V1\UpdateIngredientRequest;
use App\Http\Resources\Api\V1\IngredientResource;
use App\Models\Ingredient;
use App\Services\IngredientService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngredientController extends Controller
{
    public function __construct(private IngredientService $ingredientService) {}

    public function index(): AnonymousResourceCollection
    {
        return IngredientResource::collection(Ingredient::orderBy('name')->paginate());
    }

    public function store(StoreIngredientRequest $request): IngredientResource
    {
        return new IngredientResource($this->ingredientService->create($request->validated()));
    }

    public function show(Ingredient $ingredient): IngredientResource
    {
        return new IngredientResource($ingredient);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): IngredientResource
    {
        return new IngredientResource($this->ingredientService->update($ingredient, $request->validated()));
    }

    public function destroy(Ingredient $ingredient): Response
    {
        $ingredient->delete();

        return response()->noContent();
    }
}
