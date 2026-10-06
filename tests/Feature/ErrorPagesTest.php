<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_a_missing_record_does_not_name_the_model(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');

        $this->get($this->tenantUrl('patients/999999'))->assertNotFound()
            ->assertDontSee('App\Models', false)->assertDontSee('999999')->assertSee("We couldn't find that page");
    }

    public function test_a_crash_never_shows_its_internal_message(): void
    {
        config(['app.debug' => false]);
        Route::get('/qa-crash', fn () => throw new \RuntimeException('SQLSTATE[22007]: secret column'));

        $this->get('/qa-crash')->assertStatus(500)->assertDontSee('SQLSTATE')->assertSee('Something went wrong on our side');
    }
}
