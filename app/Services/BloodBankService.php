<?php

namespace App\Services;

use App\Models\BloodBag;
use App\Models\BloodCrossmatch;
use App\Models\BloodDonor;
use App\Models\BloodRequest;
use App\Support\Sequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BloodBankService
{
    public function __construct(protected BillingService $billing, protected IpdService $ipd) {}

    /** Donor groups a recipient can receive, per component (red cells vs plasma). */
    public static function compatibleDonorGroups(string $recipient, string $component): array
    {
        $red = [
            'O-' => ['O-'], 'O+' => ['O+', 'O-'], 'A-' => ['A-', 'O-'], 'A+' => ['A+', 'A-', 'O+', 'O-'],
            'B-' => ['B-', 'O-'], 'B+' => ['B+', 'B-', 'O+', 'O-'], 'AB-' => ['AB-', 'A-', 'B-', 'O-'],
            'AB+' => ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-'],
        ];
        $plasma = [
            'O' => ['O', 'A', 'B', 'AB'], 'A' => ['A', 'AB'], 'B' => ['B', 'AB'], 'AB' => ['AB'],
        ];

        if (in_array($component, ['ffp', 'cryo'])) {
            $abo = rtrim($recipient, '+-');

            return collect($plasma[$abo] ?? [])->flatMap(fn ($g) => [$g.'+', $g.'-'])->all();
        }

        return $red[$recipient] ?? [];
    }

    public function recordDonation(BloodDonor $donor, array $data): BloodBag
    {
        if ($donor->last_donation_date && $donor->last_donation_date->gt(today()->subDays(90))) {
            throw ValidationException::withMessages(['donation' => 'Donor is not eligible until '.fmt_date($donor->last_donation_date->copy()->addDays(90)).' (90-day interval).']);
        }

        return DB::transaction(function () use ($donor, $data) {
            $component = $data['component'] ?? 'whole_blood';
            $collected = Carbon::parse($data['collected_at'] ?? today());
            $bag = BloodBag::create([
                'bag_no' => Sequence::code('blood-bag', 'BAG', 5),
                'blood_group' => $donor->blood_group,
                'component' => $component,
                'volume_ml' => $data['volume_ml'] ?? 450,
                'blood_donor_id' => $donor->id,
                'source' => 'donation',
                'collected_at' => $collected->toDateString(),
                'expires_at' => $collected->copy()->addDays(BloodBag::SHELF_LIFE[$component] ?? 35)->toDateString(),
                'status' => 'quarantine',
                'storage_location' => $data['storage_location'] ?? null,
            ]);
            $donor->update(['last_donation_date' => $collected->toDateString()]);

            return $bag;
        });
    }

    public function crossmatch(BloodRequest $request, BloodBag $bag, string $result, ?string $notes = null): BloodCrossmatch
    {
        $this->ensureNotExpired($bag);
        if ($bag->status !== 'available') {
            throw ValidationException::withMessages(['bag' => 'Bag is not available.']);
        }
        if ($result === 'compatible' && $bag->component !== $request->component) {
            throw ValidationException::withMessages(['bag' => "Bag {$bag->bag_no} is ".(BloodBag::COMPONENTS[$bag->component] ?? $bag->component).', but the request is for '.(BloodBag::COMPONENTS[$request->component] ?? $request->component).'.']);
        }
        if (! in_array($bag->blood_group, static::compatibleDonorGroups($request->blood_group, $request->component), true) && $result === 'compatible') {
            throw ValidationException::withMessages(['bag' => "{$bag->blood_group} is not ABO/Rh compatible with {$request->blood_group}."]);
        }

        return DB::transaction(function () use ($request, $bag, $result, $notes) {
            $cm = $request->crossmatches()->create([
                'blood_bag_id' => $bag->id, 'result' => $result, 'notes' => $notes, 'tested_by' => auth()->id(), 'tested_at' => now(),
            ]);
            if ($result === 'compatible') {
                $bag->update(['status' => 'reserved']);
                $request->update(['status' => 'crossmatched']);
            }

            return $cm;
        });
    }

    public function issue(BloodCrossmatch $crossmatch, float $charge = 0): void
    {
        if ($crossmatch->result !== 'compatible' || $crossmatch->issued_at) {
            throw ValidationException::withMessages(['issue' => 'Only compatible, un-issued units can be issued.']);
        }
        $this->ensureNotExpired($crossmatch->bag, 'issue');

        DB::transaction(function () use ($crossmatch, $charge) {
            $crossmatch->update(['issued_at' => now(), 'issued_by' => auth()->id()]);
            $crossmatch->bag->update(['status' => 'issued']);
            $request = $crossmatch->request;
            $issued = $request->crossmatches()->whereNotNull('issued_at')->count();
            if ($issued >= $request->units) {
                $request->update(['status' => 'issued']);
            }

            if ($charge > 0) {
                $description = 'Blood unit '.$crossmatch->bag->bag_no.' ('.$crossmatch->bag->blood_group.' '.(BloodBag::COMPONENTS[$crossmatch->bag->component] ?? '').')';
                // A request raised before admission still belongs on the bill of the stay the unit is given in.
                $admission = $request->admission?->status === 'admitted' ? $request->admission : $request->patient->currentAdmission;
                if ($admission) {
                    $this->ipd->addCharge($admission, ['category' => 'bloodbank', 'description' => $description, 'unit_price' => $charge, 'source' => $crossmatch]);
                } elseif (hospital()->hasModule('billing')) {
                    $this->billing->createInvoice($request->patient, [['service_type' => 'bloodbank', 'description' => $description, 'unit_price' => $charge, 'source' => $crossmatch]]);
                }
            }
        });
    }

    /** The nightly job marks bags expired; until it runs, an out-of-date bag must still never be matched or issued. */
    protected function ensureNotExpired(BloodBag $bag, string $key = 'bag'): void
    {
        if ($bag->expires_at && $bag->expires_at->lt(today())) {
            $bag->update(['status' => 'expired']);
            throw ValidationException::withMessages([$key => "Bag {$bag->bag_no} expired on ".fmt_date($bag->expires_at).' and cannot be used.']);
        }
    }

    public function expireOld(): int
    {
        return BloodBag::whereIn('status', ['available', 'quarantine', 'reserved'])->whereDate('expires_at', '<', today())->update(['status' => 'expired']);
    }
}
