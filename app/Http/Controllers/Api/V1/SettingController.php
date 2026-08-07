<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateSettingRequest;
use App\Http\Resources\Api\V1\SettingResource;
use App\Models\Setting;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SettingResource::collection(Setting::orderBy('title')->get());
    }

    public function update(UpdateSettingRequest $request, Setting $setting): SettingResource
    {
        if (! $setting->editable) {
            throw ValidationException::withMessages([
                'value' => 'Esta configuracion no se puede editar.',
            ]);
        }

        $setting->update($request->validated());

        return new SettingResource($setting->fresh());
    }
}
