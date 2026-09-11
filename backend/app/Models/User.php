<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['company_id', 'name', 'email', 'phone', 'job_title', 'can_be_seller', 'is_active', 'avatar_path', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'can_be_seller' => false,
        'is_active' => true,
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
            'can_be_seller' => 'boolean',
            'is_active' => 'boolean',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withPivot('granted')
            ->withTimestamps();
    }

    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_user_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_user_id');
    }

    public function orderStatusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function orderFragments(): HasMany
    {
        return $this->hasMany(OrderFragment::class, 'created_by_user_id');
    }

    public function createdPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by_user_id');
    }

    public function confirmedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'confirmed_by_user_id');
    }

    public function rejectedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'rejected_by_user_id');
    }

    public function uploadedPaymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class, 'uploaded_by_user_id');
    }

    public function customerCreditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class, 'created_by_user_id');
    }

    public function deliveryQuotes(): HasMany
    {
        return $this->hasMany(DeliveryQuote::class, 'quoted_by_user_id');
    }

    public function requestedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'requested_by_user_id');
    }

    public function printedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'printed_by_user_id');
    }

    public function printJobEvents(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class);
    }

    public function printWaivedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'print_waived_by_user_id');
    }

    public function manualTakeoverConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'manual_takeover_by_user_id');
    }

    public function requestedAiSuggestions(): HasMany
    {
        return $this->hasMany(AiResponseSuggestion::class, 'requested_by_user_id');
    }

    public function reviewedAiSuggestions(): HasMany
    {
        return $this->hasMany(AiResponseSuggestion::class, 'reviewed_by_user_id');
    }

    public function automationEvents(): HasMany
    {
        return $this->hasMany(AutomationEvent::class, 'created_by_user_id');
    }

    public function markedComponentAvailabilities(): HasMany
    {
        return $this->hasMany(DailyComponentAvailability::class, 'marked_by_user_id');
    }

    public function markedProductComponentOverrides(): HasMany
    {
        return $this->hasMany(DailyProductComponentOverride::class, 'marked_by_user_id');
    }

    public function markedDailyMenuComponentAdjustments(): HasMany
    {
        return $this->hasMany(DailyMenuComponentAdjustment::class, 'marked_by_user_id');
    }

    public function assignRole(Role|string $role): void
    {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->where('name', $role)->firstOrFail();

        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    /**
     * @param  string|list<string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $roleNames = is_array($roles) ? $roles : [$roles];

        return $this->roles()->whereIn('name', $roleNames)->exists();
    }

    /**
     * @param  list<string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        $override = $this->permissionOverrides()
            ->where('name', $permission)
            ->first();

        if ($override instanceof Permission) {
            return (bool) $override->pivot->granted;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return $this->roles()
            ->pluck('name')
            ->sortByDesc(fn (string $role): int => Role::authorityLevel($role))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->hasRole(Role::SUPER_ADMIN)) {
            return array_keys(Permission::defaults());
        }

        $permissions = $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->keyBy(fn (string $name): string => $name);

        $this->permissionOverrides()
            ->get(['permissions.id', 'permissions.name'])
            ->each(function (Permission $permission) use ($permissions): void {
                if ((bool) $permission->pivot->granted) {
                    $permissions->put($permission->name, $permission->name);
                } else {
                    $permissions->forget($permission->name);
                }
            });

        return $permissions->keys()->values()->all();
    }

    public function authorityLevel(): int
    {
        return $this->roleNames()
            ? max(array_map(fn (string $role): int => Role::authorityLevel($role), $this->roleNames()))
            : 0;
    }

    public function primaryRoleName(): ?string
    {
        return collect($this->roleNames())
            ->sortByDesc(fn (string $role): int => Role::authorityLevel($role))
            ->first();
    }
}
