<?php

namespace App\Console\Commands;

use App\Models\LogAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra las entradas de log_actions mas viejas que el retention configurado,
 * para que la tabla de auditoria no crezca indefinidamente.
 */
#[Signature('audit:prune {--days=45 : Dias de retencion antes de borrar un registro}')]
#[Description('Elimina los registros de auditoria (log_actions) mas antiguos que el retention configurado')]
class AuditPrune extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = LogAction::where('created_at', '<', $cutoff)->delete();

        $this->info("Registros de auditoria eliminados: {$deleted}");

        return self::SUCCESS;
    }
}
