<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\FinancingCredit;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * El rastro del efectivo dia por dia, desde el ultimo cierre de caja hasta
 * hoy. SOLO LECTURA.
 *
 * Responde "¿por que no me cuadra la caja?" cuando un negocio lleva varios
 * dias sin cerrar: muestra cada turno (con que base abrio, que conto al
 * cerrar) junto al efectivo que entro ese dia, y marca los dos huecos que
 * el sistema no puede ver solo:
 *
 *  - SALTO: un turno abrio declarando una base distinta a lo que conto el
 *    turno anterior. La diferencia salio (o entro) de la caja entre los dos
 *    turnos, sin registro. Es el caso tipico del dueño que retira la venta
 *    del dia sin registrarla como gasto.
 *  - SIN CONTAR: un turno quedo abierto o se auto-cerro, asi que nadie conto
 *    ese efectivo y la cadena se corta ahi.
 *
 * Complementa a businesses:day-report (cuadres internos de UN dia).
 */
#[Signature('businesses:cash-trail {business : business_id, o parte del nombre} {--from= : Desde (YYYY-MM-DD, default: el dia siguiente al ultimo cierre)} {--to= : Hasta (YYYY-MM-DD, default hoy)}')]
#[Description('Rastro del efectivo dia por dia desde el ultimo cierre (solo lectura): turnos, ventas, gastos y donde se rompe la cadena de la base')]
class BusinessCashTrail extends Command
{
    public function handle(): int
    {
        $business = $this->resolveBusiness();
        if (! $business) {
            return self::FAILURE;
        }

        $lastClosing = CashClosing::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->orderByDesc('date')
            ->first();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : ($lastClosing ? Carbon::parse($lastClosing->date)->addDay()->startOfDay() : now()->subDays(14)->startOfDay());
        $to = Carbon::parse($this->option('to') ?: now()->toDateString())->endOfDay();

        $this->line("<fg=cyan>=== Rastro de efectivo — {$business->name} (#{$business->id}) ===</>");
        if ($lastClosing) {
            $this->line(sprintf(
                '  Ultimo cierre: %s · conto %s · dejo de base %s',
                Carbon::parse($lastClosing->date)->toDateString(),
                $this->money($lastClosing->actual_cash),
                $this->money($lastClosing->base_for_next_day),
            ));
        } else {
            $this->line('  <fg=yellow>Este negocio no tiene ningun cierre de caja.</>');
        }
        $this->line("  Rango: {$from->toDateString()} → {$to->toDateString()}");
        $this->newLine();

        $esperadoEnCaja = $lastClosing ? (float) $lastClosing->base_for_next_day : null;
        $ultimoContado = $esperadoEnCaja;
        $saltos = [];
        $sinContar = 0;
        $totalEfectivo = 0.0;
        $totalGastos = 0.0;

        foreach ($from->toPeriod($to, '1 day') as $day) {
            $date = $day->toDateString();

            $efectivo = $this->cashIncome($business, $date);
            $gastos = (float) Expense::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('scope', 'operacional')
                ->whereDate('date', $date)
                ->sum('value');

            $shifts = CashShift::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->whereDate('opened_at', $date)
                ->with('user:id,name,last_name')
                ->orderBy('opened_at')
                ->get();

            if ($shifts->isEmpty() && $efectivo == 0.0 && $gastos == 0.0) {
                continue;
            }

            $totalEfectivo += $efectivo;
            $totalGastos += $gastos;
            if ($esperadoEnCaja !== null) {
                $esperadoEnCaja += $efectivo - $gastos;
            }

            $this->line("<fg=white;options=bold>{$date}</>  ".sprintf(
                'efectivo cobrado %s · gastos %s',
                $this->money($efectivo),
                $this->money($gastos),
            ));

            foreach ($shifts as $shift) {
                $abrio = (float) $shift->opening_cash;

                if ($ultimoContado !== null && abs($abrio - $ultimoContado) > 0.5) {
                    $diff = round($abrio - $ultimoContado, 2);
                    $saltos[] = ['date' => $date, 'user' => $shift->user?->fullName() ?? '-', 'diff' => $diff, 'de' => $ultimoContado, 'a' => $abrio];
                    $this->line(sprintf(
                        '    <fg=red>SALTO</> la caja paso de %s a %s sin registro: %s',
                        $this->money($ultimoContado),
                        $this->money($abrio),
                        $this->money($diff),
                    ));
                    if ($esperadoEnCaja !== null) {
                        $esperadoEnCaja += $diff;
                    }
                }

                if ($shift->closed_at === null) {
                    $sinContar++;
                    $this->line(sprintf(
                        '    turno %s · %s · abrio %s · <fg=yellow>SIGUE ABIERTO</>',
                        $shift->opened_at->format('H:i'),
                        $shift->user?->fullName() ?? '-',
                        $this->money($abrio),
                    ));
                    $ultimoContado = null;

                    continue;
                }

                $auto = $shift->closed_via !== CashShift::CLOSED_VIA_MANUAL;
                $this->line(sprintf(
                    '    turno %s-%s · %s · abrio %s · conto %s · esperaba %s · dif %s%s',
                    $shift->opened_at->format('H:i'),
                    $shift->closed_at->format('H:i'),
                    $shift->user?->fullName() ?? '-',
                    $this->money($abrio),
                    $this->money($shift->counted_cash),
                    $this->money($shift->expected_cash),
                    $this->money($shift->difference),
                    $auto ? ' <fg=yellow>(cerrado automaticamente, nadie conto)</>' : '',
                ));

                if ($auto) {
                    $sinContar++;
                }

                $ultimoContado = (float) $shift->counted_cash;
            }

            if ($shifts->isEmpty()) {
                $this->line('    <fg=yellow>sin turnos abiertos este dia</>');
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>=== Resumen ===</>');
        $this->line('  Efectivo cobrado en el rango: '.$this->money($totalEfectivo));
        $this->line('  Gastos registrados:           '.$this->money($totalGastos));

        if ($esperadoEnCaja !== null) {
            $this->line('  Deberia haber en caja hoy:    '.$this->money($esperadoEnCaja).'  <fg=gray>(base del ultimo cierre + efectivo - gastos +/- saltos)</>');
        }
        if ($ultimoContado !== null) {
            $this->line('  Ultimo efectivo contado:      '.$this->money($ultimoContado));
        }

        if ($saltos !== []) {
            $total = round(array_sum(array_column($saltos, 'diff')), 2);
            $this->newLine();
            $this->line('<fg=red>  '.count($saltos).' salto(s) de caja sin registro, neto '.$this->money($total).':</>');
            foreach ($saltos as $s) {
                $this->line(sprintf('    %s · %s · %s → %s (%s)', $s['date'], $s['user'], $this->money($s['de']), $this->money($s['a']), $this->money($s['diff'])));
            }
            $this->line('  <fg=gray>Cada salto es plata que entro o salio de la caja entre dos turnos sin quedar registrada (retiros del dueño, cambio, prestamos).</>');
        }

        if ($sinContar > 0) {
            $this->newLine();
            $this->line("<fg=yellow>  {$sinContar} turno(s) sin conteo real (abiertos o auto-cerrados): ahi la cadena del efectivo se corta.</>");
        }

        return self::SUCCESS;
    }

    /** Todo lo que entro en efectivo ese dia, con el mismo criterio del resumen del dia. */
    private function cashIncome(Business $business, string $date): float
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereDate('closed_at', $date)
            ->with('paymentSplits')
            ->get();

        $receivables = Receivable::withoutGlobalScopes()->where('business_id', $business->id)
            ->where('status', 'paid')->whereDate('paid_at', $date)->get();
        $services = ServicePayment::withoutGlobalScopes()->where('business_id', $business->id)
            ->whereDate('created_at', $date)->get();
        $layaways = LayawayPayment::withoutGlobalScopes()->where('business_id', $business->id)
            ->whereDate('created_at', $date)->get();
        $payouts = FinancingCredit::withoutGlobalScopes()->where('business_id', $business->id)
            ->where('status', FinancingCredit::STATUS_PAID)->whereDate('paid_at', $date)->get();

        $breakdown = RevenueByPaymentMethod::combined($business, $sales, $receivables, $services, $layaways, $payouts);

        return (float) ($breakdown->firstWhere('id', $business->resolveCashPaymentMethodId())['total'] ?? 0);
    }

    private function resolveBusiness(): ?Business
    {
        $arg = (string) $this->argument('business');

        $business = is_numeric($arg)
            ? Business::find((int) $arg)
            : Business::where('name', 'like', "%{$arg}%")->first();

        if (! $business) {
            $this->error("No encontre un negocio con \"{$arg}\".");
        }

        return $business;
    }

    private function money(float|string|null $value): string
    {
        return '$'.number_format((float) $value, 0, ',', '.');
    }
}
