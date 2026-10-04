<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\HmsNotification;
use App\Support\Sequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SaaS billing lifecycle (manual / bank-transfer payments):
 *  generate invoice -> hospital uploads proof -> super admin verifies -> subscription extended.
 * Overdue invoices past the grace period suspend the hospital.
 */
class SubscriptionService
{
    public function generateInvoice(Hospital $hospital, ?Plan $plan = null, ?string $cycle = null): SubscriptionInvoice
    {
        $plan ??= $hospital->plan;
        $cycle ??= $hospital->billing_cycle;

        $open = SubscriptionInvoice::withoutHospitalScope()
            ->where('hospital_id', $hospital->id)
            ->whereIn('status', ['unpaid', 'pending_verification'])
            ->first();
        if ($open) {
            return $open;
        }

        $start = $hospital->subscription_ends_at && $hospital->subscription_ends_at->isFuture()
            ? $hospital->subscription_ends_at->copy()->addDay()
            : today();
        $end = $cycle === 'yearly' ? $start->copy()->addYear()->subDay() : $start->copy()->addMonth()->subDay();

        $amount = $plan->price($cycle);
        $taxPercent = (float) platform_setting('invoice_tax_percent', 0);
        $tax = round($amount * $taxPercent / 100, 2);

        $invoice = new SubscriptionInvoice([
            'plan_id' => $plan->id,
            'number' => Sequence::code('subscription-invoice', 'SUB', 5, 0),
            'billing_cycle' => $cycle,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'amount' => $amount,
            'tax' => $tax,
            'total' => $amount + $tax,
            'currency' => $plan->currency,
            'status' => 'unpaid',
            'due_date' => ($hospital->subscription_ends_at && $hospital->subscription_ends_at->isFuture() ? $hospital->subscription_ends_at : today()->addDays(7))->toDateString(),
        ]);
        $invoice->hospital_id = $hospital->id;
        $invoice->save();

        $this->notifyAdmins($hospital, 'New subscription invoice', "Invoice {$invoice->number} for ".money($invoice->total, $invoice->currency).' is due on '.fmt_date($invoice->due_date), 'ri-bill-line', 'warning');

        return $invoice;
    }

    public function markPaid(SubscriptionInvoice $invoice, string $method, ?string $reference = null, ?int $verifiedBy = null): void
    {
        DB::transaction(function () use ($invoice, $method, $reference, $verifiedBy) {
            $invoice->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $method,
                'payment_reference' => $reference ?? $invoice->payment_reference,
                'verified_by' => $verifiedBy,
            ])->save();

            $hospital = Hospital::findOrFail($invoice->hospital_id);

            Subscription::withoutHospitalScope()->where('hospital_id', $hospital->id)->where('status', 'active')->update(['status' => 'expired']);
            $subscription = new Subscription([
                'plan_id' => $invoice->plan_id,
                'billing_cycle' => $invoice->billing_cycle,
                'amount' => $invoice->total,
                'starts_at' => $invoice->period_start,
                'ends_at' => $invoice->period_end,
                'status' => 'active',
            ]);
            $subscription->hospital_id = $hospital->id;
            $subscription->save();
            $invoice->forceFill(['subscription_id' => $subscription->id])->save();

            $planChanged = $hospital->plan_id !== $invoice->plan_id;
            $hospital->update([
                'plan_id' => $invoice->plan_id,
                'billing_cycle' => $invoice->billing_cycle,
                'subscription_ends_at' => $invoice->period_end,
                'status' => 'active',
                'suspended_reason' => null,
            ]);

            if ($planChanged) {
                app(HospitalProvisioner::class)->syncPlanPermissions($hospital->fresh());
            }

            $this->notifyAdmins($hospital, 'Payment received', "Invoice {$invoice->number} is paid. Subscription active until ".fmt_date($invoice->period_end).'.', 'ri-checkbox-circle-line', 'success');
        });
    }

    public function changePlan(Hospital $hospital, Plan $plan, string $cycle): void
    {
        $hospital->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle]);
        app(HospitalProvisioner::class)->syncPlanPermissions($hospital->fresh());
        app(HospitalProvisioner::class)->createDefaultRoles($hospital->fresh());
        AuditLog::record('plan_changed', $hospital, [], ['plan' => $plan->name, 'cycle' => $cycle]);
    }

    public function suspend(Hospital $hospital, string $reason): void
    {
        $hospital->update(['status' => 'suspended', 'suspended_reason' => $reason]);
    }

    public function activate(Hospital $hospital): void
    {
        $hospital->update(['status' => 'active', 'suspended_reason' => null]);
    }

    /**
     * Daily job: create renewal invoices, expire trials and suspend overdue hospitals.
     *
     * @return array{invoices:int, suspended:int}
     */
    public function runDailyBilling(): array
    {
        $invoices = 0;
        $suspended = 0;
        $lead = (int) config('hms.invoice_lead_days', 7);
        $grace = (int) platform_setting('suspension_grace_days', config('hms.suspension_grace_days', 7));

        Hospital::with('plan')->whereIn('status', ['active', 'trial'])->whereNotNull('plan_id')->each(function (Hospital $hospital) use ($lead, &$invoices) {
            if ($hospital->subscription_ends_at && $hospital->subscription_ends_at->lte(today()->addDays($lead)) && $hospital->plan->price($hospital->billing_cycle) > 0) {
                $before = SubscriptionInvoice::withoutHospitalScope()->where('hospital_id', $hospital->id)->count();
                $this->generateInvoice($hospital);
                $invoices += SubscriptionInvoice::withoutHospitalScope()->where('hospital_id', $hospital->id)->count() - $before;
            }
        });

        Hospital::whereIn('status', ['active', 'trial'])
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '<', today()->subDays($grace))
            ->each(function (Hospital $hospital) use (&$suspended) {
                $this->suspend($hospital, 'Subscription expired on '.fmt_date($hospital->subscription_ends_at).' and payment was not received.');
                $suspended++;
            });

        return compact('invoices', 'suspended');
    }

    /** Monthly recurring revenue estimate across active hospitals. */
    public function mrr(): float
    {
        return (float) Hospital::with('plan')->where('status', 'active')->get()
            ->sum(fn (Hospital $h) => $h->plan ? ($h->billing_cycle === 'yearly' ? $h->plan->price_yearly / 12 : $h->plan->price_monthly) : 0);
    }

    protected function notifyAdmins(Hospital $hospital, string $title, string $message, string $icon, string $color): void
    {
        tenancy()->run($hospital, function () use ($hospital, $title, $message, $icon, $color) {
            $admins = \App\Models\User::where('hospital_id', $hospital->id)->role('Hospital Admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new HmsNotification($title, $message, route('tenant.subscription', ['hospital' => $hospital->slug]), $icon, $color));
            }
        });
    }
}
