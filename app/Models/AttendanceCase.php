<?php

namespace App\Models;

use App\Enums\AttendanceDecision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail of one customer question handled by the attendance flow.
 *
 * It always points at the conversation (and instance) it came from, so a
 * reply — or the decision not to reply — is traceable back to the customer.
 *
 * @mixin IdeHelperAttendanceCase
 */
class AttendanceCase extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'conversation_id',
        'instance_id',
        'message_id',
        'contact_id',
        'question',
        'decision',
        'answer',
        'confidence',
        'knowledge',
        'dry_run',
        'sent',
        'sent_provider_message_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'decision' => AttendanceDecision::class,
            'knowledge' => 'array',
            'confidence' => 'float',
            'dry_run' => 'boolean',
            'sent' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id', 'instance_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
