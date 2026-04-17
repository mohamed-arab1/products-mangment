<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'seller_id',
        'target_amount',
        'period_type',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'period_start' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Target $target): void {
            self::syncOwnerColumns($target);
            self::syncLegacyTargetsColumns($target);
        });

        static::updating(function (Target $target): void {
            self::syncOwnerColumns($target);
            self::syncLegacyTargetsColumns($target);
        });
    }

    /**
     * Supabase / legacy schema may use `seller_id` NOT NULL; Laravel migrations use `user_id`.
     * Keep both in sync when both columns exist; omit attributes for missing columns.
     */
    private static function syncOwnerColumns(Target $target): void
    {
        $table = $target->getTable();
        $hasUser = Schema::hasColumn($table, 'user_id');
        $hasSeller = Schema::hasColumn($table, 'seller_id');

        $owner = $target->user_id ?? $target->seller_id;
        if ($owner !== null) {
            if ($hasUser) {
                $target->setAttribute('user_id', (int) $owner);
            }
            if ($hasSeller) {
                $target->setAttribute('seller_id', (int) $owner);
            }
        }

        if (! $hasUser) {
            unset($target->attributes['user_id']);
        }
        if (! $hasSeller) {
            unset($target->attributes['seller_id']);
        }
    }

    /**
     * Supabase legacy `targets` (see supabase_schema.sql) may require month, year, achieved_amount.
     */
    private static function syncLegacyTargetsColumns(Target $target): void
    {
        $table = $target->getTable();

        if (Schema::hasColumn($table, 'achieved_amount')) {
            $v = $target->getAttribute('achieved_amount');
            if ($v === null) {
                $target->setAttribute('achieved_amount', 0);
            }
        } else {
            unset($target->attributes['achieved_amount']);
        }

        if (Schema::hasColumn($table, 'month') && Schema::hasColumn($table, 'year')) {
            $start = $target->period_start ?? $target->getAttribute('period_start');
            if ($start !== null) {
                $d = Carbon::parse($start)->startOfDay();
                $target->setAttribute('month', (int) $d->month);
                $target->setAttribute('year', (int) $d->year);
            }
        } else {
            unset($target->attributes['month'], $target->attributes['year']);
        }
    }

    public function ownerUserId(): int
    {
        return (int) ($this->user_id ?? $this->seller_id ?? 0);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
