<?php

namespace Tests\Feature;

use App\Models\BloodBag;
use App\Models\BloodRequest;
use App\Models\OtRoom;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\Surgery;
use App\Services\BloodBankService;
use App\Support\Sequence;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OtBloodBankTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    protected function bag(array $attrs): BloodBag
    {
        return BloodBag::create($attrs + ['bag_no' => Sequence::code('blood-bag', 'BAG', 5), 'volume_ml' => 350, 'source' => 'donation', 'collected_at' => today()->subDays(5), 'status' => 'available']);
    }

    public function test_expired_or_wrong_component_bags_are_never_matched(): void
    {
        $request = BloodRequest::create(['request_no' => Sequence::code('blood-request', 'BRQ'), 'patient_id' => Patient::value('id'), 'blood_group' => 'A+', 'component' => 'prbc', 'units' => 1, 'priority' => 'routine', 'status' => 'pending']);

        $expired = $this->bag(['blood_group' => 'O-', 'component' => 'prbc', 'expires_at' => today()->subDay()]);
        try {
            app(BloodBankService::class)->crossmatch($request, $expired, 'compatible');
            $this->fail('An expired bag was cross-matched.');
        } catch (ValidationException) {
            $this->assertSame('expired', $expired->fresh()->status);
        }

        $plasma = $this->bag(['blood_group' => 'O+', 'component' => 'ffp', 'expires_at' => today()->addMonth()]);
        $this->expectException(ValidationException::class);
        app(BloodBankService::class)->crossmatch($request, $plasma, 'compatible');
    }

    public function test_a_surgeon_cannot_be_booked_in_two_theatres_at_once(): void
    {
        [$roomA, $roomB] = OtRoom::take(2)->get();
        $surgeon = Staff::doctors()->firstOrFail();
        $start = now()->addDays(3)->setTime(9, 0);
        Surgery::create(['surgery_no' => Sequence::code('surgery', 'OT'), 'patient_id' => Patient::value('id'), 'ot_room_id' => $roomA->id, 'procedure_name' => 'First', 'surgery_type' => 'minor',
            'scheduled_start' => $start, 'scheduled_end' => $start->copy()->addHours(2), 'surgeon_id' => $surgeon->id, 'status' => 'scheduled']);

        Volt::test('tenant.ot.index')->call('create')
            ->set('form.patient_id', (string) Patient::value('id'))->set('form.ot_room_id', (string) $roomB->id)->set('form.procedure_name', 'Second')
            ->set('form.surgery_type', 'minor')->set('form.surgeon_id', (string) $surgeon->id)
            ->set('form.scheduled_start', $start->copy()->addHour()->format('Y-m-d\TH:i'))->set('form.scheduled_end', $start->copy()->addHours(3)->format('Y-m-d\TH:i'))
            ->call('save')->assertHasErrors('form.surgeon_id');
    }

    public function test_a_started_surgery_cannot_be_cancelled(): void
    {
        $surgery = Surgery::create(['surgery_no' => Sequence::code('surgery', 'OT'), 'patient_id' => Patient::value('id'), 'ot_room_id' => OtRoom::value('id'), 'procedure_name' => 'Started', 'surgery_type' => 'minor',
            'scheduled_start' => now(), 'scheduled_end' => now()->addHour(), 'surgeon_id' => Staff::doctors()->value('id'), 'status' => 'in_progress']);

        Volt::test('tenant.ot.show', ['surgery' => $surgery])->call('advance', 'cancelled');

        $this->assertSame('in_progress', $surgery->fresh()->status);
    }
}
