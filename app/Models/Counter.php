<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Counter extends Model
{
    protected $guarded = [];
    protected $hidden = ['display_token_hash'];

    protected function casts(): array { return ['is_paused' => 'boolean']; }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
    public function currentTicket(): ?Ticket
    {
        return $this->tickets()->whereIn('status', ['called', 'serving'])->first();
    }
}
