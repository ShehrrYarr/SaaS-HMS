<?php

namespace App\Support;

use App\Models\Hospital;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the hospital (tenant) for the current request / job.
 *
 * Tenant-owned models are filtered by HospitalScope using this context.
 * When no hospital is set the scope "fails closed" for web requests, unless
 * the scope is explicitly bypassed (Super Admin area, platform jobs).
 */
class Tenancy
{
    protected ?Hospital $hospital = null;

    protected int $bypassDepth = 0;

    protected bool $requestBypass = false;

    public function set(?Hospital $hospital): void
    {
        $this->hospital = $hospital;

        app(PermissionRegistrar::class)->setPermissionsTeamId($hospital?->id);

        // route('tenant.*') works without passing the slug — in web requests, jobs, API calls and seeders alike.
        URL::defaults(['hospital' => $hospital?->slug]);
    }

    public function hospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function id(): ?int
    {
        return $this->hospital?->id;
    }

    public function check(): bool
    {
        return $this->hospital !== null;
    }

    public function forget(): void
    {
        $this->set(null);
    }

    /** Clear all tenant state (start of every request / job, long-running workers). */
    public function reset(): void
    {
        $this->requestBypass = false;
        $this->bypassDepth = 0;
        $this->forget();
    }

    /** Super Admin area: platform users see across tenants for the whole request. */
    public function bypassForRequest(): void
    {
        $this->requestBypass = true;
    }

    public function bypassing(): bool
    {
        return $this->requestBypass || $this->bypassDepth > 0;
    }

    /** Run a callback with tenant scoping disabled. */
    public function withoutScope(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /** Run a callback inside the given hospital's context (jobs, seeders, commands). */
    public function run(Hospital $hospital, callable $callback): mixed
    {
        $previous = $this->hospital;
        $this->set($hospital);

        try {
            return $callback($hospital);
        } finally {
            $this->set($previous);
        }
    }
}
