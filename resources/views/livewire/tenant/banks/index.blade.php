<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\LedgerService;
use Illuminate\Support\Arr;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Banks & Cash')] class extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public bool $editingCash = false;

    public array $form = [];

    public bool $showTransfer = false;

    public array $transfer = [];

    public bool $showAdjust = false;

    public array $adjust = [];

    public function mount(): void
    {
        BankAccount::cash();
    }

    public function create(): void
    {
        $this->authorize('banks.manage');
        $this->editingId = null;
        $this->editingCash = false;
        $this->form = [
            'name' => '', 'account_title' => hospital()->name, 'account_number' => '', 'iban' => '', 'branch' => '',
            'opening_balance' => '0', 'opening_date' => today()->toDateString(), 'show_to_patients' => true, 'is_active' => true, 'notes' => '',
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('banks.manage');
        $a = BankAccount::findOrFail($id);
        $this->editingId = $a->id;
        $this->editingCash = $a->isCash();
        $this->form = collect($a->only(['name', 'account_title', 'account_number', 'iban', 'branch', 'opening_balance', 'notes']))->map(fn ($v) => (string) $v)->all()
            + ['opening_date' => $a->opening_date?->toDateString() ?? today()->toDateString(), 'show_to_patients' => $a->show_to_patients, 'is_active' => $a->is_active];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('banks.manage');
        $account = $this->editingId ? BankAccount::findOrFail($this->editingId) : null;
        $isCash = (bool) $account?->isCash();

        $data = $this->validate([
            'form.name' => $isCash ? 'nullable' : 'required|string|max:120',
            'form.account_title' => 'nullable|string|max:120',
            'form.account_number' => $isCash ? 'nullable' : 'required|string|max:60',
            'form.iban' => 'nullable|string|max:60',
            'form.branch' => 'nullable|string|max:120',
            'form.opening_balance' => 'required|integer',
            'form.opening_date' => 'required|date',
            'form.show_to_patients' => 'boolean',
            'form.is_active' => 'boolean',
            'form.notes' => 'nullable|string|max:500',
        ], [], ['form.name' => 'bank name', 'form.account_number' => 'account number', 'form.opening_balance' => 'opening balance'])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($isCash) {
            // Cash keeps its name and can never be switched off.
            $data = Arr::only($data, ['opening_balance', 'opening_date', 'notes']);
        }
        $account ? $account->update($data) : BankAccount::create($data + ['type' => 'bank']);

        $this->showForm = false;
        $this->toast($account ? 'Account updated.' : 'Bank added. It now appears wherever payments are recorded.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('banks.manage');
        $a = BankAccount::findOrFail($id);
        if ($a->isCash()) {
            return;
        }
        $a->update(['is_active' => ! $a->is_active]);
        $this->toast($a->is_active ? "{$a->name} is active again." : "{$a->name} is hidden from payment pickers.", $a->is_active ? 'success' : 'warning');
    }

    public function delete(int $id): void
    {
        $this->authorize('banks.manage');
        $a = BankAccount::findOrFail($id);
        if ($a->isCash() || $a->transactions()->exists()) {
            $this->toast('Accounts with transactions cannot be deleted — deactivate it instead.', 'error');

            return;
        }
        $a->delete();
        $this->toast('Bank removed.', 'warning');
    }

    public function openTransfer(?int $from = null): void
    {
        $this->authorize('banks.manage');
        $ids = BankAccount::active()->ordered()->pluck('id');
        $from = $from && $ids->contains($from) ? $from : $ids->first();
        $this->transfer = ['from' => (string) $from, 'to' => (string) $ids->first(fn ($id) => $id !== $from), 'amount' => '', 'date' => today()->toDateString(), 'note' => ''];
        $this->resetValidation();
        $this->showTransfer = true;
    }

    public function saveTransfer(LedgerService $ledger): void
    {
        $this->authorize('banks.manage');
        $this->validate([
            'transfer.from' => ['required', bank_account_exists()],
            'transfer.to' => ['required', 'different:transfer.from', bank_account_exists()],
            'transfer.amount' => 'required|integer|min:1',
            'transfer.date' => 'required|date|before_or_equal:today',
            'transfer.note' => 'nullable|string|max:150',
        ], ['transfer.to.different' => 'Choose a different account to transfer to.'], ['transfer.from' => 'from account', 'transfer.to' => 'to account', 'transfer.amount' => 'amount']);

        $from = BankAccount::findOrFail($this->transfer['from']);
        $to = BankAccount::findOrFail($this->transfer['to']);
        if ((int) $this->transfer['amount'] > $from->balance) {
            $this->addError('transfer.amount', "Only ".money($from->balance)." is available in {$from->label}.");

            return;
        }
        $at = $this->transfer['date'] === today()->toDateString() ? now() : $this->transfer['date'].' 12:00:00';
        $reference = $ledger->transfer($from, $to, (int) $this->transfer['amount'], $this->transfer['note'] ?: null, $at);

        $this->showTransfer = false;
        $this->toast('Transferred '.money($this->transfer['amount'])." from {$from->label} to {$to->label} ({$reference}).");
    }

    public function openAdjust(int $id): void
    {
        $this->authorize('banks.manage');
        $this->adjust = ['account' => $id, 'direction' => 'out', 'amount' => '', 'reason' => '', 'date' => today()->toDateString()];
        $this->resetValidation();
        $this->showAdjust = true;
    }

    public function saveAdjust(LedgerService $ledger): void
    {
        $this->authorize('banks.manage');
        $this->validate([
            'adjust.direction' => 'required|in:in,out',
            'adjust.amount' => 'required|integer|min:1',
            'adjust.reason' => 'required|string|max:150',
            'adjust.date' => 'required|date|before_or_equal:today',
        ], [], ['adjust.amount' => 'amount', 'adjust.reason' => 'reason']);

        $account = BankAccount::findOrFail($this->adjust['account']);
        $at = $this->adjust['date'] === today()->toDateString() ? now() : $this->adjust['date'].' 12:00:00';
        $ledger->adjust($account, $this->adjust['direction'], (int) $this->adjust['amount'], $this->adjust['reason'], $at);

        $this->showAdjust = false;
        $this->toast("{$account->label} balance adjusted.");
    }

    public function with(): array
    {
        $accounts = BankAccount::withTotals()->ordered()->get();
        $today = BankTransaction::whereDate('transacted_at', today())->toBase()
            ->selectRaw('bank_account_id, direction, sum(amount) total')->groupBy('bank_account_id', 'direction')->get()
            ->groupBy('bank_account_id')->map(fn ($rows) => $rows->pluck('total', 'direction'));

        $totalIn = $today->sum(fn ($d) => (float) ($d['in'] ?? 0));
        $totalOut = $today->sum(fn ($d) => (float) ($d['out'] ?? 0));

        return [
            'accounts' => $accounts,
            'today' => $today,
            'totals' => [
                'all' => $accounts->sum('balance'),
                'cash' => $accounts->where('type', 'cash')->sum('balance'),
                'banks' => $accounts->where('type', 'bank')->sum('balance'),
                'in' => $totalIn,
                'out' => $totalOut,
            ],
            'recent' => BankTransaction::with(['account', 'creator'])->latest('transacted_at')->latest('id')->limit(12)->get(),
            'activeOptions' => $accounts->where('is_active', true)->mapWithKeys(fn ($a) => [$a->id => $a->label])->all(),
            'todayHint' => '<span class="text-success">+'.e(money($totalIn)).'</span> in &middot; <span class="text-danger">-'.e(money($totalOut)).'</span> out',
        ];
    }
}; ?>

<div>
    <x-page-header title="Banks & Cash" subtitle="Where the hospital's money is">
        @can('banks.manage')
            <button class="btn btn-light-primary btn-sm" wire:click="openTransfer"><i class="ri-arrow-left-right-line me-1"></i>Transfer</button>
            <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>Add bank</button>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Total balance" :value="money($totals['all'])" icon="ri-safe-2-line" color="primary" :hint="$accounts->count().' accounts'" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Cash in hand" :value="money($totals['cash'])" icon="ri-money-rupee-circle-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="In banks" :value="money($totals['banks'])" icon="ri-bank-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Today" :value="money($totals['in'] - $totals['out'])" icon="ri-exchange-funds-line" color="warning" :hint="$todayHint" /></div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($accounts as $a)
            @php $t = $today[$a->id] ?? collect(); @endphp
            <div class="col-md-6 col-xl-4" wire:key="acct-{{ $a->id }}">
                <div class="card h-100 mb-0 {{ $a->is_active ? '' : 'opacity-75' }}">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar avatar-item rounded-2 {{ $a->isCash() ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' }}">
                                <i class="{{ $a->isCash() ? 'ri-money-rupee-circle-line' : 'ri-bank-line' }} fs-18"></i>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <h6 class="mb-0 text-truncate">{{ $a->name }}</h6>
                                <div class="fs-12 text-muted text-truncate">
                                    @if ($a->isCash())
                                        Cash in hand at the hospital
                                    @else
                                        {{ $a->account_title }}@if ($a->account_number) &middot; {{ $a->account_number }}@endif
                                    @endif
                                </div>
                                @if ($a->iban || $a->branch)<div class="fs-12 text-muted text-truncate">{{ $a->branch }}@if ($a->iban && $a->branch) &middot; @endif{{ $a->iban }}</div>@endif
                            </div>
                            <div class="d-flex flex-column align-items-end gap-1">
                                @unless ($a->is_active)<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endunless
                                @if ($a->show_to_patients && ! $a->isCash())<span class="badge bg-info-subtle text-info" title="Shown on invoices and in the patient portal">Patients</span>@endif
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="fs-12 text-muted">Current balance</div>
                            <div class="fs-3 fw-bold {{ $a->balance < 0 ? 'text-danger' : '' }}">{{ money($a->balance) }}</div>
                        </div>
                        <div class="d-flex justify-content-between fs-12 mt-2">
                            <span class="text-success"><i class="ri-arrow-down-line"></i> In today {{ money($t['in'] ?? 0) }}</span>
                            <span class="text-danger"><i class="ri-arrow-up-line"></i> Out today {{ money($t['out'] ?? 0) }}</span>
                        </div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <a href="{{ route('tenant.banks.show', $a) }}" wire:navigate class="btn btn-sm btn-light-primary"><i class="ri-file-list-3-line me-1"></i>Statement</a>
                        @can('banks.manage')
                            @if ($a->is_active)<button class="btn btn-sm btn-light" wire:click="openTransfer({{ $a->id }})" title="Transfer from this account"><i class="ri-arrow-left-right-line"></i></button>@endif
                            <button class="btn btn-sm btn-light" wire:click="edit({{ $a->id }})" title="Edit"><i class="ri-edit-line"></i></button>
                            <div class="dropdown ms-auto">
                                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="More"><i class="ri-more-2-fill"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" wire:click="openAdjust({{ $a->id }})"><i class="ri-scales-3-line me-2"></i>Adjust balance</button></li>
                                    @unless ($a->isCash())
                                        <li><button class="dropdown-item" wire:click="toggle({{ $a->id }})"><i class="{{ $a->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }} me-2"></i>{{ $a->is_active ? 'Deactivate' : 'Activate' }}</button></li>
                                        <li><button class="dropdown-item text-danger" x-on:click="$confirm(@js('Delete '.$a->name.'? Only possible while it has no transactions.'), () => $wire.delete({{ $a->id }}))"><i class="ri-delete-bin-line me-2"></i>Delete</button></li>
                                    @endunless
                                </ul>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        @endforeach
        @can('banks.manage')
            <div class="col-md-6 col-xl-4">
                <button class="card h-100 mb-0 w-100 border-dashed bg-transparent d-flex align-items-center justify-content-center text-muted py-5" style="border-style: dashed;" wire:click="create">
                    <i class="ri-add-circle-line fs-1"></i><span>Add another bank</span>
                </button>
            </div>
        @endcan
    </div>

    <div class="card mb-0">
        <div class="card-header"><h6 class="card-title mb-0">Recent transactions</h6></div>
        <div class="table-responsive">
            <table class="table table-hms mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Account</th><th>Details</th><th>Type</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead>
                <tbody>
                    @forelse ($recent as $tx)
                        <tr>
                            <td class="text-nowrap">{{ fmt_datetime($tx->transacted_at) }}</td>
                            <td><a href="{{ route('tenant.banks.show', $tx->bank_account_id) }}" wire:navigate>{{ $tx->account?->label }}</a></td>
                            <td>{{ $tx->description }} @if ($tx->reference)<span class="fs-12 text-muted">· {{ $tx->reference }}</span>@endif</td>
                            <td><span class="badge bg-light text-body">{{ label($tx->type) }}</span></td>
                            <td class="text-end text-success">{{ $tx->direction === 'in' ? money($tx->amount) : '' }}</td>
                            <td class="text-end text-danger">{{ $tx->direction === 'out' ? money($tx->amount) : '' }}</td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="6" message="No money movements yet. Payments, refunds, deposits, expenses and salaries will appear here." icon="ri-bank-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit account' : 'Add bank'">
        @if ($editingCash)
            <p class="text-muted fs-13">Cash is built in and always available. You can set its opening balance (the cash in hand when you started using the system).</p>
        @else
            <div class="row">
                <x-form.input class="col-md-6" label="Bank name" model="form.name" placeholder="e.g. HBL, Meezan Bank" required />
                <x-form.input class="col-md-6" label="Branch" model="form.branch" placeholder="e.g. Main Boulevard, Lahore" />
                <x-form.input class="col-md-6" label="Account title" model="form.account_title" />
                <x-form.input class="col-md-6" label="Account number" model="form.account_number" required />
                <x-form.input class="col-12" label="IBAN" model="form.iban" placeholder="PK36SCBL0000001123456702" />
            </div>
        @endif
        <div class="row">
            <x-form.input class="col-md-6" label="Opening balance" model="form.opening_balance" type="number" step="1" inputmode="numeric" :prepend="currency_symbol()" hint="Balance on the opening date, before any recorded transactions." />
            <x-form.input class="col-md-6" label="Opening date" model="form.opening_date" type="date" />
        </div>
        @unless ($editingCash)
            <x-form.switch label="Show to patients (invoices & patient portal) for bank transfers" model="form.show_to_patients" />
            <x-form.switch label="Active (available when recording payments)" model="form.is_active" />
        @endunless
        <x-form.textarea label="Notes" model="form.notes" rows="2" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showTransfer" title="Transfer between accounts">
        <p class="text-muted fs-13">For example, depositing the day's cash into a bank, or withdrawing cash from a bank.</p>
        <div class="row">
            <x-form.select class="col-md-6" label="From" model="transfer.from" :options="$activeOptions" :placeholder="false" required />
            <x-form.select class="col-md-6" label="To" model="transfer.to" :options="$activeOptions" :placeholder="false" required />
            <x-form.money class="col-md-6" label="Amount" model="transfer.amount" required />
            <x-form.input class="col-md-6" label="Date" model="transfer.date" type="date" />
            <x-form.input class="col-12" label="Note" model="transfer.note" placeholder="e.g. Deposit slip #2231" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveTransfer">Transfer</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showAdjust" title="Adjust balance">
        <p class="text-muted fs-13">Use this for bank charges, profit credited by the bank, or a difference found when counting cash.</p>
        <div class="row">
            <x-form.select class="col-md-6" label="Type" model="adjust.direction" :options="['in' => 'Add money (credit)', 'out' => 'Deduct money (debit)']" :placeholder="false" />
            <x-form.money class="col-md-6" label="Amount" model="adjust.amount" required />
            <x-form.input class="col-md-8" label="Reason" model="adjust.reason" placeholder="e.g. Bank service charges" required />
            <x-form.input class="col-md-4" label="Date" model="adjust.date" type="date" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveAdjust">Save</button></x-slot:footer>
    </x-modal>
</div>
