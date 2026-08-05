<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * DBのdefault(role='staff', is_demo=false)はinsert時にしか効かず、
     * create()直後のインメモリなモデルには反映されない（要refresh）ため、
     * ここでも明示しておく（Reservation/Slotのstatus初期値と同じ理由）。
     */
    protected $attributes = [
        'role' => 'staff',
        'is_demo' => false,
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
            'role' => UserRole::class,
            'is_demo' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isDemo(): bool
    {
        return $this->is_demo;
    }

    /** staff/admin とも管理画面自体には入れる（詳細設計11.1）。役割ごとの制限は各 Policy で行う。 */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
