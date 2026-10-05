<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\Staff;
use App\Services\PayrollService;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    public function test_only_recorded_absences_are_deducted_from_pay(): void
    {
        $month = now()->addMonth()->format('Y-m');
        $start = now()->addMonth()->startOfMonth();
        [$marked, $unmarked] = Staff::active()->where('basic_salary', '>', 0)->take(2)->get();

        // Partial attendance: one present day and one absence; the rest of the month is unrecorded.
        $day = $start->copy()->next('Monday');
        Attendance::create(['staff_id' => $marked->id, 'date' => $day->toDateString(), 'status' => 'present']);
        Attendance::create(['staff_id' => $marked->id, 'date' => $day->copy()->addDay()->toDateString(), 'status' => 'absent']);

        app(PayrollService::class)->generate($month);

        $p = Payroll::where('staff_id', $marked->id)->where('month', $month)->sole();
        $this->assertSame($p->working_days - 1, $p->present_days);
        $this->assertEquals(rupees($marked->basic_salary / $p->working_days), (float) $p->absence_deduction);
        $this->assertEquals(0, (float) Payroll::where('staff_id', $unmarked->id)->where('month', $month)->sole()->absence_deduction);
    }

    public function test_staff_email_is_unique_within_the_hospital(): void
    {
        $existing = Staff::whereNotNull('email')->firstOrFail();

        Volt::test('tenant.hr.staff')->call('create')->set('form.name', 'Duplicate')->set('form.email', $existing->email)
            ->call('save')->assertHasErrors('form.email');
    }
}
