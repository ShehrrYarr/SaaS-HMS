<?php

namespace App\Services\Telemedicine;

use App\Models\Appointment;
use Illuminate\Support\Str;

/**
 * Video consultation provider abstraction (Jitsi by default, Agora-ready).
 */
class VideoRoom
{
    public function driver(): string
    {
        return config('hms.telemedicine.driver', 'jitsi');
    }

    public function ensureRoom(Appointment $appointment): string
    {
        if (! $appointment->video_room) {
            $appointment->update([
                'video_room' => Str::slug(hospital()?->code ?? 'hms').'-'.$appointment->id.'-'.Str::lower(Str::random(10)),
            ]);
        }

        return $appointment->video_room;
    }

    /** Data the front-end needs to join the room. */
    public function joinConfig(Appointment $appointment, string $displayName): array
    {
        $room = $this->ensureRoom($appointment);

        return match ($this->driver()) {
            'agora' => [
                'driver' => 'agora',
                'appId' => config('hms.telemedicine.agora_app_id'),
                'channel' => $room,
                // Token generation requires the Agora access-token builder; plug it in here when going live.
                'token' => null,
                'displayName' => $displayName,
            ],
            default => [
                'driver' => 'jitsi',
                'domain' => config('hms.telemedicine.jitsi_domain', 'meet.jit.si'),
                'room' => $room,
                'displayName' => $displayName,
            ],
        };
    }
}
