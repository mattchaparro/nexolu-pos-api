<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['business_id', 'name', 'phone', 'email', 'notes'])]
class Client extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /** Max clients per business on the current plan. */
    const LIMIT_PER_BUSINESS = 50;
}
