<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * La migracion 2026_08_22_190654_backfill_split_reports_sales_permissions
     * le dio reports.business_overview a cualquier empleado que ya tuviera
     * el reports.sales viejo, para no cortarle acceso de golpe con el
     * split. Bug reportado 2026-08-24: un empleado veia "Mi negocio" sin
     * que ningun admin lo hubiera otorgado - ese backfill automatico fue
     * el causante. business_overview expone datos mas sensibles que
     * daily_summary/sales_by_seller (crecimiento, descuentos, fiado,
     * rotacion de productos), asi que a diferencia de esos dos, este
     * permiso puntual se revierte para volver al modelo opt-in: el admin
     * lo otorga a mano desde Permisos si quiere delegarlo a un empleado.
     */
    public function up(): void
    {
        $employees = User::whereHas('permissions', fn ($q) => $q->where('name', 'reports.business_overview'))->get();

        foreach ($employees as $user) {
            $user->revokePermissionTo('reports.business_overview');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intencionalmente no reversible: no hay forma de distinguir aca
        // quien tenia el permiso por el backfill original vs quien lo
        // recibio despues por una decision explicita del admin en Permisos.
    }
};
