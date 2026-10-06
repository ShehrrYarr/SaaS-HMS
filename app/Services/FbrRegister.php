<?php

namespace App\Services;

use App\Models\IpdAdmission;
use App\Models\LabOrder;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\RadiologyOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The FBR officer's register: every time a patient came to the hospital in a
 * date range — OPD visits, admissions, and walk-in lab, radiology and pharmacy
 * customers. Tests ordered during a visit or admission are listed on that
 * visit, so only stand-alone orders count as walk-ins. Cancelled records are
 * left out. Rows are tenant-scoped through the models' hospital scope.
 */
class FbrRegister
{
    public const TYPES = [
        'opd' => 'OPD visit',
        'ipd' => 'Admission',
        'lab' => 'Lab (walk-in)',
        'radiology' => 'Radiology (walk-in)',
        'pharmacy' => 'Pharmacy (walk-in)',
    ];

    /** The longest range one screen or export may cover. */
    public const MAX_DAYS = 366;

    /** dompdf gets slow on long tables; bigger ranges go to CSV. */
    public const PDF_MAX_ROWS = 2000;

    /**
     * A safe [from, to] (Y-m-d) from user input: defaults to this month so far,
     * swaps a reversed range and trims it to MAX_DAYS ending on "to".
     */
    public static function range(?string $from, ?string $to): array
    {
        $parse = function (?string $date) {
            try {
                return $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('!Y-m-d', $date) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $start = $parse($from) ?? today()->startOfMonth();
        $end = $parse($to) ?? today();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    /** One reference row (type, id, at) per encounter, newest first. */
    public function query(string $from, string $to): Builder
    {
        $range = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $part = fn ($eloquent, string $type, string $column) => $eloquent->toBase()
            ->selectRaw("'{$type}' as type, id, {$column} as at")
            ->whereBetween($column, $range)
            ->where('status', '!=', 'cancelled');

        return $part(OpdVisit::query(), 'opd', 'visit_date')
            ->unionAll($part(IpdAdmission::query(), 'ipd', 'admitted_at'))
            ->unionAll($part(LabOrder::whereNull('visitable_id'), 'lab', 'ordered_at'))
            ->unionAll($part(RadiologyOrder::whereNull('visitable_id'), 'radiology', 'created_at'))
            ->unionAll($part(PharmacySale::whereNull('prescription_id')->whereNull('ipd_admission_id'), 'pharmacy', 'created_at'))
            ->orderByDesc('at')->orderByDesc('id');
    }

    /** Turn reference rows into display rows (eager-loads each type in one go). */
    public function rows(iterable $refs): Collection
    {
        $refs = collect($refs);
        $ids = fn (string $type) => $refs->where('type', $type)->pluck('id')->all();
        $patient = ['patient' => fn ($q) => $q->withTrashed()];
        $clinical = array_merge($patient, ['doctor', 'department', 'diagnoses', 'labOrders.items.test', 'radiologyOrders.test', 'prescriptions.items']);

        $records = [
            'opd' => OpdVisit::with($clinical)->findMany($ids('opd'))->keyBy('id'),
            'ipd' => IpdAdmission::with(array_merge($clinical, ['surgeries', 'pharmacySales.items.medicine']))->findMany($ids('ipd'))->keyBy('id'),
            'lab' => LabOrder::with(array_merge($patient, ['doctor', 'items.test']))->findMany($ids('lab'))->keyBy('id'),
            'radiology' => RadiologyOrder::with(array_merge($patient, ['doctor', 'test']))->findMany($ids('radiology'))->keyBy('id'),
            'pharmacy' => PharmacySale::with(array_merge($patient, ['items.medicine']))->findMany($ids('pharmacy'))->keyBy('id'),
        ];

        return $refs->map(function ($ref) use ($records) {
            $record = $records[$ref->type][$ref->id] ?? null;

            return $record ? $this->{$ref->type}($record) : null;
        })->filter()->values();
    }

    /** Every row in the range, a page at a time (exports). */
    public function each(string $from, string $to, callable $callback, int $chunk = 500): void
    {
        for ($page = 1; ; $page++) {
            $refs = $this->query($from, $to)->forPage($page, $chunk)->get();
            $this->rows($refs)->each($callback);
            if ($refs->count() < $chunk) {
                return;
            }
        }
    }

    protected function opd(OpdVisit $v): array
    {
        return $this->row('opd', $v->visit_no, $v->visit_date, $v->patient, $v->department?->name, $v->doctor?->name,
            $this->diagnoses($v) ?: $v->chief_complaint, $this->clinicalServices($v));
    }

    protected function ipd(IpdAdmission $a): array
    {
        $services = $this->clinicalServices($a);
        $services['Surgery'] = $a->surgeries->where('status', '!=', 'cancelled')->pluck('procedure_name')->unique()->values()->all();
        $services['Medicines'] = collect($services['Medicines'])
            ->merge($a->pharmacySales->where('status', '!=', 'cancelled')->flatMap->items->map(fn ($i) => $i->medicine?->name))
            ->filter()->unique()->values()->all();

        return $this->row('ipd', $a->admission_no, $a->admitted_at, $a->patient, $a->department?->name, $a->doctor?->name,
            $this->diagnoses($a) ?: ($a->provisional_diagnosis ?: $a->reason), $services);
    }

    protected function lab(LabOrder $o): array
    {
        return $this->row('lab', $o->order_no, $o->ordered_at, $o->patient, 'Laboratory', $o->doctor?->name, null,
            ['Lab tests' => $o->items->where('status', '!=', 'cancelled')->map(fn ($i) => $i->test?->name)->filter()->values()->all()]);
    }

    protected function radiology(RadiologyOrder $o): array
    {
        return $this->row('radiology', $o->order_no, $o->created_at, $o->patient, 'Radiology', $o->doctor?->name, null,
            ['Imaging' => array_filter([$o->test?->name])]);
    }

    protected function pharmacy(PharmacySale $s): array
    {
        $medicines = $s->items->map(fn ($i) => $i->medicine ? $i->medicine->name.' × '.$i->quantity : null)->filter()->values()->all();
        $row = $this->row('pharmacy', $s->sale_no, $s->created_at, $s->patient, 'Pharmacy', null, null, ['Medicines' => $medicines]);

        if (! $s->patient) {
            $row['patient'] = ['name' => $s->customer_name ?: 'Walk-in customer', 'uhid' => null, 'cnic' => null, 'age_gender' => null, 'phone' => $s->customer_phone, 'address' => null];
        }

        return $row;
    }

    protected function row(string $type, string $ref, $at, ?Patient $p, ?string $department, ?string $doctor, ?string $diagnosis, array $services): array
    {
        return [
            'type' => $type,
            'type_label' => self::TYPES[$type],
            'ref' => $ref,
            'at' => $at,
            'patient' => [
                'name' => $p?->full_name ?? 'Unknown patient',
                'uhid' => $p?->uhid,
                'cnic' => $p?->cnic,
                'age_gender' => $p?->age_gender,
                'phone' => $p?->phone,
                'address' => $p ? (collect([$p->address, $p->city])->filter()->join(', ') ?: null) : null,
            ],
            'department' => $department,
            'doctor' => $doctor,
            'diagnosis' => $diagnosis ?: null,
            'services' => array_filter($services),
        ];
    }

    /** Final diagnoses first, e.g. "Acute pharyngitis (J02.9)". */
    protected function diagnoses(OpdVisit|IpdAdmission $visit): ?string
    {
        return $visit->diagnoses->sortBy(fn ($d) => $d->type === 'final' ? 0 : 1)
            ->map(fn ($d) => $d->description.($d->icd_code ? " ({$d->icd_code})" : ''))
            ->unique()->join('; ') ?: null;
    }

    protected function clinicalServices(OpdVisit|IpdAdmission $visit): array
    {
        return [
            'Lab tests' => $visit->labOrders->where('status', '!=', 'cancelled')->flatMap->items
                ->where('status', '!=', 'cancelled')->map(fn ($i) => $i->test?->name)->filter()->unique()->values()->all(),
            'Imaging' => $visit->radiologyOrders->where('status', '!=', 'cancelled')->map(fn ($o) => $o->test?->name)->filter()->unique()->values()->all(),
            'Medicines' => $visit->prescriptions->where('status', '!=', 'cancelled')->flatMap->items->pluck('medicine_name')->filter()->unique()->values()->all(),
        ];
    }
}
