<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Staff;
use App\Services\OpdService;
use App\Support\AllergyCheck;
use Carbon\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OpdFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    protected function newVisit(?Patient $patient = null, ?Staff $doctor = null): OpdVisit
    {
        return app(OpdService::class)->createVisit($patient ?? Patient::firstOrFail(), $doctor ?? Staff::doctors()->firstOrFail());
    }

    public function test_allergy_check_matches_drug_families_and_ignores_food_allergies(): void
    {
        $patient = Patient::firstOrFail();
        $patient->allergies()->delete();
        $patient->allergies()->create(['allergen' => 'Penicillin', 'type' => 'drug', 'severity' => 'severe']);
        $patient->allergies()->create(['allergen' => 'Peanut', 'type' => 'food', 'severity' => 'mild']);
        $patient->load('allergies');

        $this->assertSame('Penicillin (severe)', AllergyCheck::describe(AllergyCheck::conflicts($patient, 'Augmentin 625mg')));
        $this->assertCount(0, AllergyCheck::conflicts($patient, 'Panadol 500mg'));
        $this->assertCount(0, AllergyCheck::conflicts($patient, 'Peanut oil drops'));
    }

    public function test_prescribing_against_an_allergy_needs_an_explicit_override(): void
    {
        $visit = $this->newVisit();
        $visit->patient->allergies()->create(['allergen' => 'Penicillin', 'type' => 'drug', 'severity' => 'severe']);
        $before = Prescription::count();

        $component = Volt::test('tenant.opd.consult', ['visit' => $visit])
            ->set('items.0.medicine_name', 'Amoxicillin 500mg')
            ->call('savePrescription')
            ->assertHasErrors('allergy');
        $this->assertSame($before, Prescription::count());

        $component->set('allergyOverride', true)->call('savePrescription')->assertHasNoErrors();
        $this->assertSame($before + 1, Prescription::count());
        $this->assertTrue(AuditLog::where('event', 'allergy_override')->exists());
    }

    public function test_the_queue_will_not_call_a_second_patient_while_one_is_with_the_doctor(): void
    {
        $doctor = Staff::doctors()->firstOrFail();
        OpdVisit::where('doctor_id', $doctor->id)->whereIn('status', ['waiting', 'in_consultation'])->update(['status' => 'completed']);
        [$first, $second] = [$this->newVisit(Patient::find(1), $doctor), $this->newVisit(Patient::find(2), $doctor)];

        $queue = Volt::test('tenant.queue.index')->call('callNext', $doctor->id);
        $this->assertSame('in_consultation', $first->fresh()->status);

        $queue->call('callNext', $doctor->id)->call('setStatus', $second->id, 'in_consultation');
        $this->assertSame('waiting', $second->fresh()->status);
    }

    public function test_a_follow_up_on_the_doctors_day_off_is_not_reported_as_booked(): void
    {
        $visit = $this->newVisit();
        $visit->update(['status' => 'in_consultation']);
        $sunday = today()->next(Carbon::SUNDAY)->toDateString();

        $component = Volt::test('tenant.opd.consult', ['visit' => $visit])->set('items.0.medicine_name', 'Panadol 500mg')->call('savePrescription');
        $component->set('followUp', $sunday)->call('complete')->assertRedirect();

        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertFalse(Appointment::where('patient_id', $visit->patient_id)->whereDate('appointment_date', $sunday)->exists());
        $this->assertStringContainsString('no free slot', session('warning'));
        $this->assertSame($sunday, $visit->prescriptions()->first()->follow_up_date->toDateString());
    }

    public function test_only_open_appointments_on_or_before_today_can_be_marked_no_show(): void
    {
        $future = Appointment::whereIn('status', ['booked', 'confirmed'])->whereDate('appointment_date', '>', today())->firstOrFail();
        $checkedIn = Appointment::where('status', 'checked_in')->firstOrFail();

        Volt::test('tenant.appointments.index')->call('setStatus', $future->id, 'no_show')->call('setStatus', $checkedIn->id, 'no_show');

        $this->assertNotSame('no_show', $future->fresh()->status);
        $this->assertSame('checked_in', $checkedIn->fresh()->status);
    }
}
