<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

#[Fillable(['vendor_id', 'user_id', 'action', 'subject_type', 'subject_id', 'description'])]
#[UseFactory(ActivityLogFactory::class)]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    /**
     * Record an activity entry for the currently authenticated user (or the
     * system - e.g. a payment gateway webhook or a self-service action from
     * the customer app - if no staff/admin user is authenticated).
     */
    public static function record(
        ActivityAction $action,
        string $subjectType,
        ?int $subjectId,
        string $description,
        ?int $vendorId,
    ): self {
        $actor = Auth::user();

        return self::create([
            'vendor_id' => $vendorId,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
