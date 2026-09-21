<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'auth_provider', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * Organizations this user belongs to, via the organization_members pivot.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    public function responsibleProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'responsible_user_id');
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
