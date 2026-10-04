<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Staff;
use App\Support\Sequence;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    /**
     * Free slots for a doctor on a date: schedule slots minus booked ones (and past times today).
     *
     * @return array<string,string> "H:i" => "09:15 AM"
     */
    public function availableSlots(Staff $doctor, string $date, ?int $ignoreAppointmentId = null): array
    {
        $day = Carbon::parse($date);
        if ($doctor->leaves()->whereDate('date', $day)->exists()) {
            return [];
        }

        $booked = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $day)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
            ->pluck('start_time')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();

        $slots = [];
        foreach ($doctor->schedules()->where('is_active', true)->where('day_of_week', $day->dayOfWeek)->orderBy('start_time')->get() as $schedule) {
            $count = Appointment::where('doctor_id', $doctor->id)->whereDate('appointment_date', $day)->whereNotIn('status', ['cancelled', 'no_show'])
                ->whereBetween('start_time', [$schedule->start_time, $schedule->end_time])->count();
            if ($schedule->max_patients && $count >= $schedule->max_patients) {
                continue;
            }
            foreach ($schedule->slots() as $slot) {
                if (in_array($slot, $booked, true)) {
                    continue;
                }
                if ($day->isToday() && Carbon::parse($date.' '.$slot)->lt(now())) {
                    continue;
                }
                $slots[$slot] = Carbon::parse($slot)->format('h:i A').($schedule->room ? ' · '.$schedule->room : '');
            }
        }

        return $slots;
    }

    public function book(Patient $patient, Staff $doctor, array $data): Appointment
    {
        $slot = $data['start_time'] ?? null;
        if ($slot && ! array_key_exists(substr($slot, 0, 5), $this->availableSlots($doctor, $data['appointment_date'], $data['id'] ?? null))) {
            throw ValidationException::withMessages(['form.start_time' => 'That slot is no longer available.']);
        }
        $minutes = $doctor->schedules()->where('day_of_week', Carbon::parse($data['appointment_date'])->dayOfWeek)->value('slot_minutes') ?? 15;

        $attributes = [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'department_id' => $doctor->department_id,
            'appointment_date' => $data['appointment_date'],
            'start_time' => $slot,
            'end_time' => $slot ? Carbon::parse($slot)->addMinutes($minutes)->format('H:i') : null,
            'source' => $data['source'] ?? 'walk_in',
            'mode' => $data['mode'] ?? 'in_person',
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'fee' => $data['fee'] ?? $doctor->consultation_fee,
        ];

        if (! empty($data['id'])) {
            $appointment = Appointment::findOrFail($data['id']);
            $appointment->update($attributes);

            return $appointment;
        }

        return Appointment::create($attributes + [
            'appointment_no' => Sequence::code('appointment', 'APT'),
            'status' => ($data['source'] ?? 'walk_in') === 'portal' ? 'booked' : 'confirmed',
            'created_by' => auth()->id(),
        ]);
    }
}
