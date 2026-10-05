<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Surgery extends Model
{
    use BelongsToHospital, Auditable;

    protected $table = 'surgeries';

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'pre_op_checklist' => 'array',
            'post_op_checklist' => 'array',
            'charges' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(IpdAdmission::class, 'ipd_admission_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(OtRoom::class, 'ot_room_id');
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'surgeon_id');
    }

    public function team(): HasMany
    {
        return $this->hasMany(SurgeryTeam::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public const PRE_OP_CHECKLIST = [
        'consent' => 'Informed consent signed', 'identity' => 'Patient identity & site marked', 'fasting' => 'NPO / fasting confirmed',
        'allergies' => 'Allergies reviewed', 'anesthesia' => 'Anesthesia assessment done', 'blood' => 'Blood arranged / cross-matched',
        'investigations' => 'Pre-op investigations reviewed', 'antibiotic' => 'Prophylactic antibiotic given',
    ];

    public const POST_OP_CHECKLIST = [
        'count' => 'Instrument & sponge count correct', 'specimen' => 'Specimen labelled & sent', 'vitals' => 'Vitals stable',
        'pain' => 'Pain management plan', 'orders' => 'Post-op orders written', 'handover' => 'Handover to ward / ICU',
    ];

    public const TEAM_ROLES = ['assistant_surgeon' => 'Assistant Surgeon', 'anesthetist' => 'Anesthetist', 'scrub_nurse' => 'Scrub Nurse', 'circulating_nurse' => 'Circulating Nurse', 'technician' => 'OT Technician'];
}
