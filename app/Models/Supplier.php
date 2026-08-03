<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'name', 'tax_id', 'phone', 'address', 'notes'])]
class Supplier extends Model
{
    use BelongsToBusiness, HasFactory;
}
