<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBusinessRequest;
use App\Http\Resources\Api\V1\BusinessResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BusinessController extends Controller
{
    public function show(Request $request): BusinessResource
    {
        return new BusinessResource($this->currentBusiness($request));
    }

    public function update(UpdateBusinessRequest $request): BusinessResource
    {
        $business = $this->currentBusiness($request);
        $business->update($request->validated());

        return new BusinessResource($business->fresh());
    }

    private function currentBusiness(Request $request)
    {
        $business = $request->user()?->business;

        if (! $business) {
            throw new NotFoundHttpException('El usuario autenticado no tiene un negocio asociado.');
        }

        return $business;
    }
}
