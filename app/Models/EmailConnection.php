<?php
namespace App\Models;
use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'provider', 'email_address', 'access_token', 'refresh_token',
        'token_expires_at', 'status', 'last_synced_at', 'sync_status',
    ];

    protected $hidden = [
        'access_token',   // OAuth token — encrypted at rest, never expose
        'refresh_token',  // OAuth refresh — encrypted at rest, never expose
    ];

    protected function casts(): array
    {
        return [
            'access_token'    => 'encrypted',   // AES-256-CBC
            'refresh_token'   => 'encrypted',   // AES-256-CBC
            'token_expires_at'=> 'datetime',
            'last_synced_at'  => 'datetime',
            'status'          => ConnectionStatus::class,
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function parsedEmails(): HasMany { return $this->hasMany(ParsedEmail::class); }
}
