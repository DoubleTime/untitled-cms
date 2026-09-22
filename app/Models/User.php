<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use App\Traits\HasRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, HasUlidKey, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'customer_id',
        'password',
        'is_active',
        'email_verified_at',
        'social_accounts',
        'bounce_hard',
        'session_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'social_accounts', // Contains linked provider IDs — not needed by frontend
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'bounce_hard' => 'boolean',
            'social_accounts' => 'array',
            'session_version' => 'integer',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * The Customer this user belongs to — set only for Customer Users, who reach
     * the catalogue through RPA-TOOL. Team Members leave it null.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * True when the user is a Customer User (belongs to a Customer).
     * Not an authorisation check on its own — admin access is gated by
     * canAccessBackend(), and the Customer label is never an access wall.
     */
    public function isCustomerUser(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * True when at least one of the user's roles has backend_access enabled.
     * Use this — not getCachedPermissions() — to gate admin area access.
     */
    public function canAccessBackend(): bool
    {
        if (! $this->id) {
            return false;
        }

        return Cache::remember('user_backend_access_'.$this->id, 60, function () {
            // Query directly to avoid caching a stale in-memory roles collection.
            return $this->roles()->where('backend_access', true)->where('is_active', true)->exists();
        });
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getCachedPermissions());
    }

    public function getCachedPermissions(): array
    {
        if (! $this->id) {
            return [];
        }

        return Cache::remember('user_permissions_'.$this->id, 60, function () {
            $permissions = [];
            foreach ($this->roles as $role) {
                foreach ($role->permissions ?? [] as $permission) {
                    if ($extracted = $this->extractPermissionName($permission)) {
                        $permissions[] = $extracted;
                    }
                }
            }

            return array_values(array_unique($permissions));
        });
    }

    private function extractPermissionName(mixed $permission): ?string
    {
        if (is_string($permission)) {
            return $permission;
        }

        if (is_array($permission) && isset($permission['name'])) {
            return $permission['name'];
        }

        return null;
    }

    /**
     * Sync user roles and bust the permission cache so the change is immediately visible.
     */
    public function syncRoles(array $roleIds): void
    {
        $this->roles()->sync($roleIds);
        Cache::forget('user_permissions_'.$this->id);
        Cache::forget('user_backend_access_'.$this->id);
    }
}
