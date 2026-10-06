<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-IP cap on failed sign-ins across every account and login page. The per-email limit
 * (5 a minute) alone lets one address try a common password against many accounts.
 */
class LoginThrottle
{
    public const MAX_FAILURES = 30;

    public const WINDOW_SECONDS = 900;

    protected static function key(): string
    {
        return 'login-ip:'.request()->ip();
    }

    public static function blocked(): bool
    {
        return RateLimiter::tooManyAttempts(static::key(), static::MAX_FAILURES);
    }

    /** Seconds until this IP may try again (0 when it is not blocked). */
    public static function retryAfter(): int
    {
        return static::blocked() ? RateLimiter::availableIn(static::key()) : 0;
    }

    public static function failed(): void
    {
        RateLimiter::hit(static::key(), static::WINDOW_SECONDS);
    }
}
