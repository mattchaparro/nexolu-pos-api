<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseTypeRequest;
use App\Http\Resources\Api\V1\ExpenseTypeResource;
use App\Models\ExpenseType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ExpenseTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = $request->user()->business_id;

        $this->seedDefaultsIfNeeded($businessId);

        $types = ExpenseType::query()
            ->where(fn ($q) => $q->where('business_id', $businessId)->orWhereNull('business_id'))
            ->orderBy('name')
            ->get();

        return ExpenseTypeResource::collection($types);
    }

    public function store(StoreExpenseTypeRequest $request): ExpenseTypeResource
    {
        $businessId = $request->user()->business_id;
        $baseSlug = Str::slug($request->string('name'));
        $slug = $baseSlug;
        $suffix = 1;
        while (ExpenseType::where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$baseSlug}-{$suffix}";
        }

        $type = ExpenseType::create([
            'business_id' => $businessId,
            'name' => $request->string('name'),
            'slug' => $slug,
        ]);

        return new ExpenseTypeResource($type);
    }

    private function seedDefaultsIfNeeded(?int $businessId): void
    {
        if (! $businessId || ExpenseType::where('business_id', $businessId)->exists()) {
            return;
        }

        foreach (ExpenseType::DEFAULT_NAMES as $name) {
            ExpenseType::firstOrCreate(
                ['business_id' => $businessId, 'name' => $name],
                ['slug' => Str::slug($name)]
            );
        }
    }
}
