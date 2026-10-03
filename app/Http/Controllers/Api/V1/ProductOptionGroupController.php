<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductOptionGroupResource;
use App\Models\ProductOptionGroup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductOptionGroupController extends Controller
{
    /** Biblioteca de grupos del negocio, con los productos que usa cada uno (para reutilizarlos o copiarlos). */
    public function index(): AnonymousResourceCollection
    {
        return ProductOptionGroupResource::collection(
            ProductOptionGroup::with(['options', 'products:id,name'])->orderBy('name')->get()
        );
    }
}
