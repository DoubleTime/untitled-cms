<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Shown to a Customer User who tries the web login. Deliberately says nothing
     * about whether the password was right.
     */
    public const RPA_TOOL_ONLY_MESSAGE = 'This account can only be used from RPA-TOOL.';

    /**
     * True when this account exists only for RPA-TOOL — it is linked to a Customer,
     * or it carries the `customer` role and no role granting backend access.
     */
    public static function isRpaToolOnly(User $user): bool
    {
        if ($user->isCustomerUser()) {
            return true;
        }

        return $user->hasRole('customer') && ! $user->canAccessBackend();
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $this->rejectCustomerUser();
    }

    /**
     * Customer Users reach the catalogue only through RPA-TOOL (docs/adr/0002):
     * they must never hold a web session, even with the right password. The
     * credentials have already been accepted here, so tear the session back down
     * before failing.
     *
     * @throws ValidationException
     */
    protected function rejectCustomerUser(): void
    {
        $user = Auth::user();

        if (! $user || ! static::isRpaToolOnly($user)) {
            return;
        }

        Auth::guard('web')->logout();
        $this->session()->invalidate();
        $this->session()->regenerateToken();

        throw ValidationException::withMessages([
            'email' => static::RPA_TOOL_ONLY_MESSAGE,
        ]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
