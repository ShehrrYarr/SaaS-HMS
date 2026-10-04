<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\DoctorCommission;
use App\Models\Payroll;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll: basic + allowances + doctor commissions − fixed deductions − absence deduction.
 * Working days exclude Sundays. If no attendance was recorded for a staff member in the month,
 * no absence deduction is applied (attendance is optional).
 */
class PayrollService
{
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
                $present = $attendance->isEmpty()
                    ? $workingDays
                    : $attendance->whereIn('status', ['present', 'late', 'leave', 'holiday'])->count() + 0.5 * $attendance->where('status', 'half_day')->count();
                $absentDays = max(0, $workingDays - $present);
                $absenceDeduction = $attendance->isEmpty() ? 0 : round((float) $staff->basic_salary / max(1, $workingDays) * $absentDays, 2);

                $commissions = DoctorCommission::where('staff_id', $staff->id)->where('status', 'pending')
                    ->where(fn ($q) => $q->whereNull('payroll_id')->orWhere('payroll_id', $existing?->id))
                    ->whereDate('earned_at', '<=', $end->toDateString())->get();
                $commission = round((float) $commissions->sum('amount'), 2);

                $net = round((float) $staff->basic_salary + (float) $staff->allowances + $commission - (float) $staff->deductions - $absenceDeduction, 2);
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

    public function pay(Payroll $payroll, string $method): void
    {
        if ($payroll->status !== 'approved') {
            throw ValidationException::withMessages(['payroll' => 'Approve the payroll before paying.']);
        }
        DB::transaction(function () use ($payroll, $method) {
            $payroll->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $method]);
            DoctorCommission::where('payroll_id', $payroll->id)->update(['status' => 'paid']);
        });
    }
}
