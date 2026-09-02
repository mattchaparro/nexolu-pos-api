<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['business_id', 'name', 'tax_id', 'phone', 'address', 'notes'])]
class Supplier extends Model
{
    use BelongsToBusiness, HasFactory;

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Las lineas de todas sus compras. Existe para poder sumar el total
     * comprado con withSum() en vez de traerse cada compra a memoria: el
     * total de una compra es la suma de sus lineas, no una columna.
     */
    public function purchaseLines(): HasManyThrough
    {
        return $this->hasManyThrough(PurchaseLine::class, Purchase::class);
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }
}
