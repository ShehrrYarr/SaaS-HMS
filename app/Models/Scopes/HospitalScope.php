<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class HospitalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = tenancy();

        if ($tenancy->bypassing()) {
            return;
        }

        if ($tenancy->check()) {
            $builder->where($model->qualifyColumn('hospital_id'), $tenancy->id());

            return;
        }

        // Console (seeders, commands, tests) operate unscoped unless a tenant is set.
        if (app()->runningInConsole()) {
            return;
        }

        // Web request without a tenant context: fail closed to prevent data leakage.
        $builder->whereRaw('1 = 0');
    }
}
