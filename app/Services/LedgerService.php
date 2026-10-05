<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Banks & Cash: every rupee that enters or leaves a hospital account is a line here,
 * so each account's balance = opening balance + money in - money out.
 */
class LedgerService
{
    /** Resolve an account id/model (or "cash") to an active account of the current hospital. */
    public function account(BankAccount|int|string $account): BankAccount
    {
        $model = match (true) {
            $account instanceof BankAccount => $account,
            $account === 'cash' => BankAccount::cash(),
            default => BankAccount::find((int) $account),
        };
        if (! $model || $model->is_active === false) {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose an active bank or cash account.']);
        }

        return $model;
    }

    public function record(BankAccount|int|string $account, string $direction, int|float $amount, string $type, ?Model $source = null, ?string $description = null, ?string $reference = null, $at = null): BankTransaction
    {
        $amount = rupees($amount);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return BankTransaction::create([
            'bank_account_id' => $this->account($account)->id,
            'direction' => $direction === 'out' ? 'out' : 'in',
            'amount' => $amount,
            'type' => $type,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'reference' => $reference,
            'description' => $description,
            'transacted_at' => $at ?? now(),
            'created_by' => auth()->id(),
        ]);
    }

    public function moneyIn(BankAccount|int|string $account, int|float $amount, string $type, ?Model $source = null, ?string $description = null, ?string $reference = null, $at = null): BankTransaction
    {
        return $this->record($account, 'in', $amount, $type, $source, $description, $reference, $at);
    }

    public function moneyOut(BankAccount|int|string $account, int|float $amount, string $type, ?Model $source = null, ?string $description = null, ?string $reference = null, $at = null): BankTransaction
    {
        return $this->record($account, 'out', $amount, $type, $source, $description, $reference, $at);
    }

    /** Remove the lines written for a document (e.g. before re-posting an edited expense). */
    public function forget(Model $source): void
    {
        BankTransaction::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->delete();
    }

    /** (Re)post an expense as money paid out of its account. */
    public function postExpense(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $this->forget($expense);
            $this->moneyOut($expense->bank_account_id ?: 'cash', $expense->amount, 'expense', $expense,
                'Expense · '.$expense->title.($expense->paid_to ? " · {$expense->paid_to}" : ''), $expense->reference, $expense->expense_date);
        });
    }

    /** Move money between two accounts, e.g. depositing the day's cash into a bank. */
    public function transfer(BankAccount $from, BankAccount $to, int|float $amount, ?string $note = null, $at = null): string
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['transfer.to' => 'Choose two different accounts.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $note, $at) {
            $reference = Sequence::code('bank-transfer', 'TRF');
            $this->moneyOut($from, $amount, 'transfer', null, trim("Transfer to {$to->label}. {$note}"), $reference, $at);
            $this->moneyIn($to, $amount, 'transfer', null, trim("Transfer from {$from->label}. {$note}"), $reference, $at);
            AuditLog::record('bank_transfer', $from, [], ['to' => $to->id, 'amount' => rupees($amount), 'reference' => $reference], "Transferred ".money($amount)." to {$to->label}");

            return $reference;
        });
    }

    /** Manual correction, e.g. bank charges or a counted cash difference. */
    public function adjust(BankAccount $account, string $direction, int|float $amount, string $reason, $at = null): BankTransaction
    {
        $line = $this->record($account, $direction, $amount, 'adjustment', null, $reason, null, $at);
        AuditLog::record('bank_adjustment', $account, [], ['direction' => $line->direction, 'amount' => $line->amount, 'reason' => $reason], 'Balance adjustment');

        return $line;
    }

    public function balance(BankAccount $account, $until = null): int
    {
        $sum = fn (string $direction) => (float) $account->transactions()->where('direction', $direction)
            ->when($until, fn ($q) => $q->where('transacted_at', '<', $until))->sum('amount');

        return rupees($account->opening_balance + $sum('in') - $sum('out'));
    }
}
