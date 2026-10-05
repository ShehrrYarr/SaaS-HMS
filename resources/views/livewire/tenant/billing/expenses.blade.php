<?php

use App\Livewire\Concerns\WithTable;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Expenses')] class extends Component
{
    use WithFileUploads, WithTable;

    protected string $defaultSort = 'expense_date';

    protected array $sortable = ['expense_date', 'amount'];

    #[Url]
    public string $category = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public $attachment;

    public string $newCategory = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['expense_category_id' => '', 'title' => '', 'amount' => '', 'tax_amount' => 0, 'expense_date' => today()->toDateString(), 'paid_to' => '', 'bank_account_id' => (string) BankAccount::cash()->id, 'reference' => '', 'notes' => ''];
        $this->attachment = null;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $e = Expense::findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($e->only(['expense_category_id', 'title', 'amount', 'tax_amount', 'paid_to', 'bank_account_id', 'reference', 'notes']))->map(fn ($v) => (string) $v)->all() + ['expense_date' => $e->expense_date->toDateString()];
        if (! BankAccount::active()->whereKey($e->bank_account_id)->exists()) {
            $this->form['bank_account_id'] = (string) BankAccount::cash()->id;
        }
        $this->resetValidation();
        $this->showForm = true;
    }

    public function addCategory(): void
    {
        $this->validate(['newCategory' => 'required|string|max:80']);
        $this->form['expense_category_id'] = (string) ExpenseCategory::create(['name' => $this->newCategory])->id;
        $this->newCategory = '';
    }

    public function save(LedgerService $ledger): void
    {
        $this->authorize('expenses.manage');
        $data = $this->validate([
            'form.expense_category_id' => ['nullable', tenant_exists('expense_categories')],
            'form.title' => 'required|string|max:150',
            'form.amount' => 'required|integer|min:1',
            'form.tax_amount' => 'nullable|integer|min:0',
            'form.expense_date' => 'required|date',
            'form.paid_to' => 'nullable|string|max:150',
            'form.bank_account_id' => ['required', bank_account_exists()],
            'form.reference' => 'nullable|string|max:100',
            'form.notes' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
        ], [], ['form.bank_account_id' => 'paid from'])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['tax_amount'] ??= 0;
        $data['payment_method'] = BankAccount::find($data['bank_account_id'])->type;
        if ($this->attachment) {
            $data['attachment_path'] = $this->attachment->store(hospital()->storagePath('expenses'), 'local');
        }
        DB::transaction(function () use ($data, $ledger) {
            $expense = $this->editingId ? tap(Expense::findOrFail($this->editingId))->update($data) : Expense::create($data + ['created_by' => auth()->id()]);
            $ledger->postExpense($expense);
        });
        $this->showForm = false;
        $this->toast('Expense saved.');
    }

    public function delete(int $id, LedgerService $ledger): void
    {
        $this->authorize('expenses.manage');
        $expense = Expense::findOrFail($id);
        DB::transaction(function () use ($expense, $ledger) {
            $ledger->forget($expense);
            $expense->delete();
        });
        $this->toast('Expense deleted; '.money($expense->amount).' is back in '.($expense->account?->label ?? 'Cash').'.', 'warning');
    }

    public function with(): array
    {
        $filters = fn ($q) => $q
            ->when($this->category, fn ($q) => $q->where('expense_category_id', $this->category))
            ->when($this->from, fn ($q) => $q->whereDate('expense_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('expense_date', '<=', $this->to))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$this->search}%")->orWhere('paid_to', 'like', "%{$this->search}%")));

        return [
            'expenses' => $this->applySort($filters(Expense::with(['category', 'creator', 'account'])))->paginate($this->perPage),
            'total' => (float) $filters(Expense::query())->sum('amount'),
            'byCategory' => $filters(Expense::query())->toBase()->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->selectRaw("coalesce(expense_categories.name, 'Uncategorised') name, sum(expenses.amount) total")->groupBy('name')->orderByDesc('total')->get(),
            'categories' => ExpenseCategory::orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Expenses" subtitle="Expense management" :breadcrumbs="['Billing' => route('tenant.billing.invoices')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>Record expense</button>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-9">
            <div class="card mb-0">
                <x-table-toolbar placeholder="Title or payee...">
                    <input type="date" class="form-control w-auto" wire:model.live="from">
                    <input type="date" class="form-control w-auto" wire:model.live="to">
                    <select class="form-select w-auto" wire:model.live="category"><option value="">All categories</option>@foreach ($categories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                </x-table-toolbar>
                <div class="table-responsive">
                    <table class="table table-hms table-hover mb-0">
                        <thead class="table-light"><tr><x-th field="expense_date" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Title</th><th>Category</th><th>Paid to</th><th>Paid from</th><x-th field="amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Amount</x-th><th></th></tr></thead>
                        <tbody>
                            @forelse ($expenses as $e)
                                <tr wire:key="exp-{{ $e->id }}">
                                    <td>{{ fmt_date($e->expense_date) }}</td>
                                    <td>{{ $e->title }} @if ($e->attachment_path)<a href="{{ route('files.show', ['path' => $e->attachment_path]) }}" target="_blank"><i class="ri-attachment-2"></i></a>@endif<div class="fs-12 text-muted">{{ $e->reference }}</div></td>
                                    <td>{{ $e->category?->name ?? '—' }}</td><td>{{ $e->paid_to }}</td><td>{{ $e->account?->label ?? label($e->payment_method) }}</td>
                                    <td class="text-end">{{ money($e->amount) }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $e->id }})"><i class="ri-edit-line"></i></button>
                                        <button class="btn btn-sm btn-light-danger icon-btn-sm" x-on:click="$confirm('Delete expense?', () => $wire.delete({{ $e->id }}))"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="7" message="No expenses in this period." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $expenses->links() }}</div>
            </div>
        </div>
        <div class="col-xl-3">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Total · {{ money($total) }}</h6></div>
                <ul class="list-group list-group-flush">
                    @foreach ($byCategory as $row)<li class="list-group-item d-flex justify-content-between"><span>{{ $row->name }}</span><strong>{{ money($row->total) }}</strong></li>@endforeach
                </ul>
            </div>
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit expense' : 'Record expense'">
        <x-form.input label="Title" model="form.title" required />
        <div class="row">
            <div class="col-md-6 mb-3">
                <x-form.select class="mb-1" label="Category" model="form.expense_category_id" :options="$categories" />
                <div class="input-group input-group-sm"><input type="text" class="form-control" placeholder="New category" wire:model="newCategory"><button type="button" class="btn btn-light" wire:click="addCategory">Add</button></div>
            </div>
            <x-form.input class="col-md-6" label="Date" model="form.expense_date" type="date" />
            <x-form.money class="col-md-6" label="Amount" model="form.amount" required />
            <x-form.money class="col-md-6" label="Tax included" model="form.tax_amount" />
            <x-form.input class="col-md-6" label="Paid to" model="form.paid_to" />
            <x-form.account class="col-md-6" label="Paid from" model="form.bank_account_id" />
            <x-form.input class="col-md-6" label="Reference" model="form.reference" />
            <div class="col-md-6 mb-3"><label class="form-label">Receipt</label><input type="file" class="form-control" wire:model="attachment"></div>
            <x-form.textarea class="col-12" label="Notes" model="form.notes" rows="2" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
