<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Exercises Model::toResource()/Collection::toResourceCollection(): `owner`/`staff` resolve by
 * convention, `historyEvent` via #[UseResource], `filing`/`alert` have no resolvable resource.
 */
class Merchant extends Model
{
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function historyEvent(): BelongsTo
    {
        return $this->belongsTo(TrackingEvent::class);
    }

    public function filing(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class);
    }
}
