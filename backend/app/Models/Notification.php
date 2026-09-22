<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per the ERD (PROJECT_CONTEXT.md Sprint 8 "Notifications"): directly organization_id AND
 * user_id scoped, same convention as Client/Payment — uses BelongsToOrganization directly
 * rather than an indirect parent-relationship hop.
 *
 * Naming note: this is `App\Models\Notification`, distinct from the framework's
 * `Illuminate\Notifications\Notification` (the abstract base class for mailable/broadcastable
 * notification classes) and `Illuminate\Notifications\DatabaseNotification` (the Eloquent model
 * backing Laravel's own built-in `notifications` table when using the `database` notification
 * channel). Different namespace, different table, no class-name collision — `php artisan
 * tinker` instantiates `App\Models\Notification` without ambiguity (verified). The `User` model
 * previously used `Illuminate\Notifications\Notifiable`, which defines its own `notifications()`
 * / `unreadNotifications()` / `readNotifications()` relations against `DatabaseNotification` —
 * nothing in this codebase ever called `->notify()` or used that built-in system (grepped: zero
 * hits beyond the trait import itself), so the trait was dead weight. It has been removed from
 * `User` so this model's own `notifications(): HasMany` relation (below) is the only thing named
 * `notifications()` on `User`, avoiding a silent method-shadowing trap where the trait's
 * `unreadNotifications()` would have kept querying `DatabaseNotification` while a same-named
 * relation on the model queried this table instead.
 *
 * No Auditable trait: notifications aren't a commercial record needing their own audit trail.
 */
#[Fillable([
    'organization_id', 'user_id', 'channel', 'type', 'payload_json', 'sent_at', 'read_at',
])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This user's unread notifications — the primary query pattern per PROJECT_CONTEXT.md
     * ("this user's unread notifications, newest first"), backed by the (user_id, read_at)
     * index added in the migration.
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
