<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted state machine for the one-question-at-a-time guided interview (INT-01 / D3).
 *
 * STATE MACHINE:
 *   created → in_progress → paused → in_progress (resume, INT-05) → completed
 *   Transitions are managed by InterviewOrchestratorService.
 *
 * QUEUE / ASKED:
 *   'queue' is an ordered JSONB array of fact_keys yet to be asked.
 *   'asked' is a JSONB array of fact_keys already asked in this session.
 *   Both contain non-PII namespaced strings only (e.g. 'ira.balance_range').
 *
 * ASSERTIONS (encrypted transcript):
 *   'assertions' is an encrypted TEXT column storing the ordered Q/A transcript
 *   as a JSON array. Each entry: {fact_key, question, answer, answered_at, question_id}.
 *   NEVER index or query this column — it's encrypted and contains user responses.
 *
 * PARTIAL UNIQUE INDEX (idx_interview_sessions_one_in_progress):
 *   At most one in_progress session per (user_id, tax_year). Enforced at DB level.
 *   Use InterviewOrchestratorService::startOrResume() which handles firstOrCreate
 *   safely (Pitfall 8 — concurrent dispatch race).
 *
 * GDPR: cascadeOnDelete on user_id FK ensures this table is wiped when the user
 * account is deleted (UserProfileController::deleteAccount()).
 *
 * SECURITY (T-11-04-03): Always query through scopeForUser() to prevent
 * cross-user information disclosure.
 */
class InterviewSession extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'status',
        'queue',
        'asked',
        'skipped',
        'assertions',
        'initial_cap',
        'format_version', // D20: schema version stamp
    ];

    /**
     * SECURITY (D12): assertions contains the encrypted Q/A transcript.
     * Excluded from API responses / toArray().
     */
    protected $hidden = [
        'assertions',
    ];

    /**
     * Laravel 12 method-syntax casts.
     *
     * NOTE on assertions encryption: the assertions column stores a JSON array
     * BEFORE encryption. The encrypted cast wraps the raw JSON string. When read
     * back, it's decrypted to the JSON string and must be json_decode()'d manually.
     * (Using 'encrypted:array' would double-encode — keep as 'encrypted' + manual
     * json_encode/decode to match the ParsedEmail.raw_parsed_data precedent.)
     */
    protected function casts(): array
    {
        return [
            'queue' => 'array',
            'asked' => 'array',
            'skipped' => 'array',
            'assertions' => 'encrypted',  // TEXT column (AES-256-GCM via Laravel)
        ];
    }

    // ─── State Machine Helpers ────────────────────────────────────────────────

    /**
     * Check if the session can accept new questions (is active).
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['created', 'in_progress', 'paused'], true);
    }

    /**
     * Check if the session is complete (no more questions to ask).
     */
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Transition to in_progress (from any active state).
     * Safe to call multiple times (idempotent if already in_progress).
     */
    public function activate(): void
    {
        if ($this->status !== 'in_progress') {
            $this->update(['status' => 'in_progress']);
        }
    }

    /**
     * Transition to paused (from in_progress only).
     */
    public function pause(): void
    {
        if ($this->status === 'in_progress') {
            $this->update(['status' => 'paused']);
        }
    }

    /**
     * Transition to completed (marks the session as done).
     */
    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }

    /**
     * Add a fact_key to the asked array (records that this key was presented).
     * Deduplicates in case of concurrent calls.
     */
    public function markAsked(string $factKey): void
    {
        $asked = $this->asked ?? [];
        if (! in_array($factKey, $asked, true)) {
            $asked[] = $factKey;
            $this->update(['asked' => $asked]);
        }
    }

    /**
     * Add a fact_key to the skipped[] array (DEFECT 2 — durable skip persistence).
     * Deduplicates. Skipped keys are excluded from the stale-queue self-heal rebuild
     * so a skipped item never returns to the head of the queue on reload.
     */
    public function markSkipped(string $factKey): void
    {
        $skipped = $this->skipped ?? [];
        if (! in_array($factKey, $skipped, true)) {
            $skipped[] = $factKey;
            $this->update(['skipped' => $skipped]);
        }
    }

    /**
     * Remove a fact_key from queue[] (called after nextQuestion() pops the key).
     */
    public function dequeueKey(string $factKey): void
    {
        $queue = $this->queue ?? [];
        $queue = array_values(array_filter($queue, fn ($k) => $k !== $factKey));
        $this->update(['queue' => $queue]);
    }

    /**
     * Append a Q/A pair to the encrypted assertions transcript (INT-05 / D3).
     *
     * The transcript is stored as an encrypted JSON array. Each entry:
     *   { fact_key, question, answer, answered_at, question_id }
     *
     * Called by InterviewOrchestratorService::recordAnswer() to maintain
     * the ordered provenance log.
     */
    public function appendTranscript(array $entry): void
    {
        // Decode existing transcript (assertions is decrypted automatically by cast)
        $transcript = $this->assertions ? json_decode($this->assertions, true) : [];
        if (! is_array($transcript)) {
            $transcript = [];
        }

        $transcript[] = array_merge($entry, ['appended_at' => now()->toIso8601String()]);

        // Re-encode and store (the 'encrypted' cast encrypts on save)
        $this->update(['assertions' => json_encode($transcript)]);
    }
}
