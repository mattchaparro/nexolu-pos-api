<?php

namespace App\Support;

use App\Models\LogAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Consultas compartidas para listado y exportacion de auditoria - un solo
 * lugar para la logica de filtro que antes vivia duplicada dentro de
 * SuperAdmin\AuditLogController::filtered().
 */
class AuditLogQuery
{
    public static function forBusiness(Request $request, int $businessId): Builder
    {
        $query = LogAction::with(['user'])->where('business_id', $businessId);

        self::applySearch($query, $request);

        return $query;
    }

    public static function forSuperadmin(Request $request): Builder
    {
        $query = LogAction::with(['user', 'business']);

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->integer('business_id'));
        }

        self::applySearch($query, $request);

        return $query;
    }

    private static function applySearch(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $query->where('action', 'like', '%'.$request->string('search').'%');
        }
    }
}
