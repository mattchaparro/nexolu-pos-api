<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * reports.sales dejo de cubrir Resumen del dia/Mi negocio/Ventas por
     * vendedor (ahora cada uno tiene su propio permiso - ver
     * App\Support\PermissionCatalog) - sin este backfill, cualquier
     * empleado que ya tuviera reports.sales asignado directo perderia esos
     * 3 reportes en el momento en que este deploy entra en produccion. Se
     * le dan los 3 permisos nuevos a quien ya tenia reports.sales para que
     * su acceso efectivo no cambie con el split; el admin puede despues
     * recortarlo desde la pantalla de Permisos si quiere ser mas granular
     * con alguien puntual. Los admin no necesitan esto: ya reciben todo el
     * catalogo via su rol (ver PermissionCatalog::sync()).
     */
    public function up(): void
    {
        foreach (['reports.daily_summary', 'reports.business_overview', 'reports.sales_by_seller'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $employeesWithSalesReports = User::whereHas('permissions', fn ($q) => $q->where('name', 'reports.sales'))->get();

        foreach ($employeesWithSalesReports as $user) {
            $user->givePermissionTo(['reports.daily_summary', 'reports.business_overview', 'reports.sales_by_seller']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $employeesWithSalesReports = User::whereHas('permissions', fn ($q) => $q->where('name', 'reports.sales'))->get();

        foreach ($employeesWithSalesReports as $user) {
            $user->revokePermissionTo(['reports.daily_summary', 'reports.business_overview', 'reports.sales_by_seller']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
