<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\DoctorCommission;
use App\Models\Payroll;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll: basic + allowances + doctor commissions − fixed deductions − absence deduction.
 * Working days exclude Sundays. Only recorded absences are deducted (absent = 1 day, half day = ½):
 * a day nobody marked is not treated as absent, so a payroll run mid-month or with partial
 * attendance doesn't dock weeks of pay.
 */
class PayrollService
{
    public function __construct(protected LedgerService $ledger) {}

    public function generate(string $month): int
    {
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();
        $workingDays = collect(range(1, $start->daysInMonth))->reject(fn ($d) => $start->copy()->day($d)->isSunday())->count();
        $count = 0;

        DB::transaction(function () use ($month, $start, $end, $workingDays, &$count) {
            foreach (Staff::active()->get() as $staff) {
                $existing = Payroll::where('staff_id', $staff->id)->where('month', $month)->first();
                if ($existing && $existing->status !== 'draft') {
                    continue;
                }

                $attendance = Attendance::where('staff_id', $staff->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
                $absentDays = min($workingDays, $attendance->where('status', 'absent')->count() + 0.5 * $attendance->where('status', 'half_day')->count());
                $present = $workingDays - $absentDays;
                $absenceDeduction = rupees($staff->basic_salary / max(1, $workingDays) * $absentDays);

                $commissions = DoctorCommission::where('staff_id', $staff->id)->where('status', 'pending')
                    ->where(fn ($q) => $q->whereNull('payroll_id')->orWhere('payroll_id', $existing?->id))
                    ->whereDate('earned_at', '<=', $end->toDateString())->get();
                $commission = rupees($commissions->sum('amount'));

                $net = $staff->basic_salary + $staff->allowances + $commission - $staff->deductions - $absenceDeduction;
                if ($net <= 0 && (float) $staff->basic_salary <= 0 && $commission <= 0) {
                    continue;
                }

                $payroll = Payroll::updateOrCreate(['staff_id' => $staff->id, 'month' => $month], [
                    'working_days' => $workingDays,
                    'present_days' => (int) ceil($present),
                    'basic' => $staff->basic_salary,
                    'allowances' => $staff->allowances,
                    'commission' => $commission,
                    'deductions' => $staff->deductions,
                    'absence_deduction' => $absenceDeduction,
                    'net_pay' => max(0, $net),
                    'status' => 'draft',
                    'processed_by' => auth()->id(),
                ]);
                DoctorCommission::whereIn('id', $commissions->pluck('id'))->update(['payroll_id' => $payroll->id]);
                $count++;
            }
        });

        return $count;
    }

    public function approve(Payroll $payroll): void
    {
        if ($payroll->status !== 'draft') {
            throw ValidationException::withMessages(['payroll' => 'Only draft payroll can be approved.']);
        }
        $payroll->update(['status' => 'approved']);
    }

    /** Pay an approved payroll out of a bank / cash account ('cash' = the Cash account). */
    public function pay(Payroll $payroll, BankAccount|int|string $account = 'cash'): void
    {
        if ($payroll->status !== 'approved') {
            throw ValidationException::withMessages(['payroll' => 'Approve the payroll before paying.']);
        }
        $account = $this->ledger->account($account);
        DB::transaction(function () use ($payroll, $account) {
            $payroll->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $account->type, 'bank_account_id' => $account->id]);
            DoctorCommission::where('payroll_id', $payroll->id)->update(['status' => 'paid']);
            if ($payroll->net_pay > 0) {
                $this->ledger->moneyOut($account, $payroll->net_pay, 'salary', $payroll, "Salary {$payroll->month} · ".$payroll->staff?->name);
            }
        });
    }
}
