<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['business_id', 'name', 'phone', 'identification', 'email', 'notes'])]
class Client extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /**
     * Tope historico del plan basico. Se conserva como default para
     * negocios sin plan, pero el numero que manda es el de
     * Business::clientLimit(): 50 fichas se llenan en semanas en cuanto un
     * negocio vende por internet, y quedarse sin poder registrar al
     * comprador convierte el CRM en ruido a la mitad.
     */
    const LIMIT_PER_BUSINESS = 50;
}
