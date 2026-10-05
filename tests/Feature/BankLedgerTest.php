<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Bed;
use App\Models\Expense;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\Payroll;
use App\Models\Staff;
use App\Services\BillingService;
use App\Services\IpdService;
use App\Services\LedgerService;
use App\Services\PharmacyService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BankLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('accounts@cityhospital.test');
    }

    protected function bank(string $name = 'HBL'): BankAccount
    {
        return BankAccount::where('name', $name)->firstOrFail();
    }

    public function test_amounts_are_whole_rupees_rounded_to_nearest(): void
    {
        $invoice = app(BillingService::class)->createInvoice(Patient::firstOrFail(), [
            ['description' => 'Consultation', 'unit_price' => 999, 'tax_percent' => 5],   // tax 49.95 -> 50
            ['description' => 'Dressing', 'quantity' => 0.5, 'unit_price' => 301],          // 150.5 -> 151
        ]);

        $this->assertSame(1200, $invoice->total);
        $this->assertSame(50, $invoice->tax);
        $this->assertSame('Rs 1,250', money(1249.5));
        $this->assertSame('Rs 62', money(62.49));
    }

    public function test_payments_go_into_the_chosen_account_and_refunds_come_out(): void
    {
        $billing = app(BillingService::class);
        $hbl = $this->bank();
        $cash = BankAccount::cash();
        [$hblBefore, $cashBefore] = [$hbl->balance, $cash->balance];

        $invoice = $billing->createInvoice(Patient::firstOrFail(), [['description' => 'MRI', 'unit_price' => 20000]]);
        $payment = $billing->addPayment($invoice, 15000, $hbl, 'TRX-991');
        $billing->addPayment($invoice->fresh(), 2000, $cash->id, null, true);

        $this->assertSame($hbl->id, $payment->bank_account_id);
        $this->assertSame('bank', $payment->method);
        $this->assertSame($hblBefore + 15000, $hbl->fresh()->balance);
        $this->assertSame($cashBefore - 2000, $cash->fresh()->balance);
        $this->assertSame(13000, $invoice->fresh()->paid_amount);
    }

    public function test_invoice_payment_screen_records_the_account_and_rejects_decimals(): void
    {
        $invoice = app(BillingService::class)->createInvoice(Patient::firstOrFail(), [['description' => 'X-Ray', 'unit_price' => 1500]]);
        $meezan = $this->bank('Meezan Bank');

        Volt::test('tenant.billing.show', ['invoice' => $invoice])->call('openPay', false)
            ->set('pay.amount', '10.5')->call('savePayment')->assertHasErrors(['pay.amount' => 'integer'])
            ->set('pay.amount', '1500')->set('pay.account', (string) $meezan->id)->call('savePayment')->assertHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($meezan->id, $invoice->payments()->first()->bank_account_id);
    }

    public function test_walk_in_pharmacy_sale_and_its_return_move_money(): void
    {
        $hbl = $this->bank();
        $before = $hbl->balance;
        $medicine = Medicine::create(['name' => 'Ledger Test 10mg', 'form' => 'tablet', 'unit' => 'strip', 'purchase_price' => 50, 'sale_price' => 80, 'reorder_level' => 1]);
        MedicineBatch::create(['medicine_id' => $medicine->id, 'batch_no' => 'LT1', 'expiry_date' => now()->addYear(), 'quantity_received' => 10, 'quantity_available' => 10, 'sale_price' => 80]);

        $sale = app(PharmacyService::class)->sell([['medicine_id' => $medicine->id, 'quantity' => 3]], ['bank_account_id' => $hbl->id]);
        $this->assertSame($before + 240, $hbl->fresh()->balance);

        $this->actingAsTenantUser('pharmacist@cityhospital.test');
        Volt::test('tenant.pharmacy.sales')->call('view', $sale->id)->set('refundAccount', (string) $hbl->id)->call('returnSale', $sale->id);
        $this->assertSame('returned', $sale->fresh()->status);
        $this->assertSame($before, $hbl->fresh()->balance);
    }

    public function test_ipd_deposit_posts_at_admission_and_is_not_counted_again_at_discharge(): void
    {
        $ipd = app(IpdService::class);
        $cash = BankAccount::cash();
        $before = $cash->balance;
        $patient = Patient::whereDoesntHave('admissions', fn ($q) => $q->where('status', 'admitted'))->firstOrFail();

        $admission = $ipd->admit($patient, ['doctor_id' => Staff::doctors()->value('id'), 'bed_id' => Bed::where('status', 'available')->value('id'), 'deposit_amount' => 5000, 'deposit_account_id' => $cash->id]);
        $this->assertSame($before + 5000, $cash->fresh()->balance);

        $invoice = $ipd->discharge($admission->fresh(), ['discharge_summary' => 'Recovered']);
        $this->assertSame($before + 5000, $cash->fresh()->balance);
        $this->assertSame('deposit', $invoice->payments()->first()->method);
        $this->assertNull($invoice->payments()->first()->bank_account_id);
    }

    public function test_expenses_follow_edits_and_deletes(): void
    {
        $hbl = $this->bank();
        $cash = BankAccount::cash();
        [$hblBefore, $cashBefore] = [$hbl->balance, $cash->balance];

        Volt::test('tenant.billing.expenses')->call('create')
            ->set('form.title', 'Water bill')->set('form.amount', '12000')->set('form.bank_account_id', (string) $hbl->id)
            ->call('save')->assertHasNoErrors();
        $expense = Expense::where('title', 'Water bill')->firstOrFail();
        $this->assertSame($hblBefore - 12000, $hbl->fresh()->balance);

        Volt::test('tenant.billing.expenses')->call('edit', $expense->id)->set('form.amount', '9000')->set('form.bank_account_id', (string) $cash->id)->call('save')->assertHasNoErrors();
        $this->assertSame($hblBefore, $hbl->fresh()->balance);
        $this->assertSame($cashBefore - 9000, $cash->fresh()->balance);

        Volt::test('tenant.billing.expenses')->call('delete', $expense->id);
        $this->assertSame($cashBefore, $cash->fresh()->balance);
    }

    public function test_salaries_are_paid_out_of_the_selected_account(): void
    {
        $hbl = $this->bank();
        $before = $hbl->balance;
        Volt::test('tenant.hr.payroll')->call('generate')->call('approveAll')->set('payAccount', (string) $hbl->id)->call('payAll');

        $paid = Payroll::where('status', 'paid')->get();
        $this->assertNotEmpty($paid);
        $this->assertSame($before - (int) $paid->sum('net_pay'), $hbl->fresh()->balance);
        $this->assertTrue($paid->every(fn ($p) => $p->bank_account_id === $hbl->id));
    }

    public function test_banks_page_adds_banks_and_transfers_without_overdrawing(): void
    {
        Volt::test('tenant.banks.index')->call('create')
            ->set('form.name', 'Bank Alfalah')->set('form.account_number', '5500 1234 5678')->set('form.opening_balance', '100000')
            ->call('save')->assertHasNoErrors();
        $alfalah = BankAccount::where('name', 'Bank Alfalah')->firstOrFail();
        $this->assertArrayHasKey($alfalah->id, BankAccount::options());

        $cash = BankAccount::cash();
        Volt::test('tenant.banks.index')->call('openTransfer', $alfalah->id)->set('transfer.to', (string) $cash->id)
            ->set('transfer.amount', '100001')->call('saveTransfer')->assertHasErrors('transfer.amount')
            ->set('transfer.amount', '30000')->call('saveTransfer')->assertHasNoErrors();
        $this->assertSame(70000, $alfalah->fresh()->balance);

        Volt::test('tenant.banks.show', ['account' => $alfalah])->assertSee('Bank Alfalah')->assertSee('Transfer to Cash');
    }

    public function test_only_finance_roles_see_balances(): void
    {
        $this->get($this->tenantUrl('banks'))->assertOk()->assertSee('Banks &amp; Cash', false);

        $this->actingAsTenantUser('reception@cityhospital.test');
        $this->get($this->tenantUrl('banks'))->assertForbidden();
    }

    public function test_another_hospitals_account_cannot_be_used(): void
    {
        $sunriseCash = tenancy()->run($this->hospital('sunrise-clinic'), fn () => BankAccount::cash());
        $invoice = app(BillingService::class)->createInvoice(Patient::firstOrFail(), [['description' => 'Test', 'unit_price' => 100]]);

        $this->expectException(ValidationException::class);
        app(BillingService::class)->addPayment($invoice, 100, $sunriseCash->id);
    }

    public function test_ledger_balance_respects_dates(): void
    {
        $ledger = app(LedgerService::class);
        $account = BankAccount::create(['type' => 'bank', 'name' => 'Dated Bank', 'account_number' => '1', 'opening_balance' => 1000]);
        $ledger->moneyIn($account, 500, 'adjustment', null, 'Old credit', null, now()->subDays(10));
        $ledger->moneyOut($account, 200, 'adjustment', null, 'Recent debit');

        $this->assertSame(1500, $ledger->balance($account, today()->subDays(2)));
        $this->assertSame(1300, $account->fresh()->balance);
    }
}
