@extends('pdf.layout')
@section('title', 'Payslip '.$p->month)
@section('content')
    <h2 class="center">PAYSLIP — {{ \Carbon\Carbon::parse($p->month.'-01')->format('F Y') }}</h2>
    <table class="box" style="margin-top: 8px;">
        <tr><td><span class="muted">Employee:</span> <b>{{ $p->staff->display_name }}</b></td><td><span class="muted">Code:</span> {{ $p->staff->employee_code }}</td><td><span class="muted">Department:</span> {{ $p->staff->department?->name ?? '—' }}</td></tr>
        <tr><td><span class="muted">Designation:</span> {{ $p->staff->designation }}</td><td><span class="muted">Bank:</span> {{ $p->staff->bank_name }} {{ $p->staff->bank_account }}</td><td><span class="muted">Attendance:</span> {{ $p->present_days }}/{{ $p->working_days }} days</td></tr>
    </table>
    <table style="margin-top: 10px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 6px;">
                <table class="grid"><thead><tr><th>Earnings</th><th class="right">Amount</th></tr></thead><tbody>
                    <tr><td>Basic salary</td><td class="right">{{ money($p->basic) }}</td></tr>
                    <tr><td>Allowances</td><td class="right">{{ money($p->allowances) }}</td></tr>
                    @if ($p->commission > 0)<tr><td>Consultation commission ({{ $p->commissions->count() }})</td><td class="right">{{ money($p->commission) }}</td></tr>@endif
                    <tr><td class="bold">Gross</td><td class="right bold">{{ money($p->basic + $p->allowances + $p->commission) }}</td></tr>
                </tbody></table>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 6px;">
                <table class="grid"><thead><tr><th>Deductions</th><th class="right">Amount</th></tr></thead><tbody>
                    <tr><td>Fixed deductions</td><td class="right">{{ money($p->deductions) }}</td></tr>
                    <tr><td>Absence ({{ max(0, $p->working_days - $p->present_days) }} day(s))</td><td class="right">{{ money($p->absence_deduction) }}</td></tr>
                    <tr><td class="bold">Total deductions</td><td class="right bold">{{ money($p->deductions + $p->absence_deduction) }}</td></tr>
                </tbody></table>
            </td>
        </tr>
    </table>
    <div class="box" style="margin-top: 12px; font-size: 13px;"><b>Net pay: {{ money($p->net_pay) }}</b> <span class="muted small">· {{ strtoupper($p->status) }} {{ $p->paid_at ? 'on '.$p->paid_at->format('d M Y').' from '.($p->account?->label ?? str_replace('_', ' ', $p->payment_method)) : '' }}</span></div>
    <p class="muted small" style="margin-top: 30px;">This is a computer-generated payslip and does not require a signature.</p>
@endsection
