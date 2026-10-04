<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Search + sort + per-page for list components (Bootstrap pagination).
 * Use applySort($query) in the component's query builder.
 */
trait WithTable
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $sortField = '';

    public string $sortDirection = '';

    public int $perPage = 0;

    /**
     * Components configure defaults with protected $defaultSort, $defaultDirection, $defaultPerPage
     * (PHP forbids re-declaring trait properties with different defaults).
     */
    public function mountWithTable(): void
    {
        $this->sortField = $this->sortField ?: ($this->defaultSort ?? 'id');
        $this->sortDirection = $this->sortDirection ?: ($this->defaultDirection ?? 'desc');
        $this->perPage = $this->perPage ?: ($this->defaultPerPage ?? 10);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    protected function applySort($query)
    {
        $allowed = property_exists($this, 'sortable') ? $this->sortable : [$this->sortField];
        $field = in_array($this->sortField, $allowed, true) ? $this->sortField : ($allowed[0] ?? 'id');

        return $query->orderBy($field, $this->sortDirection === 'asc' ? 'asc' : 'desc');
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }
}
