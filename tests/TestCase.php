<?php

namespace Tests;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seed the full demo dataset once (each test then runs inside a transaction). */
    protected bool $seed = true;

    protected function hospital(string $slug = 'city-hospital'): Hospital
    {
        return Hospital::where('slug', $slug)->firstOrFail();
    }

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Act as a hospital user with the tenant context primed (as IdentifyHospital would). */
    protected function actingAsTenantUser(string $email, string $slug = 'city-hospital'): User
    {
        $hospital = $this->hospital($slug);
        tenancy()->set($hospital);
        $user = $this->userByEmail($email);
        $this->actingAs($user);

        return $user;
    }

    protected function tenantUrl(string $path, string $slug = 'city-hospital'): string
    {
        return "/h/{$slug}/".ltrim($path, '/');
    }
}
