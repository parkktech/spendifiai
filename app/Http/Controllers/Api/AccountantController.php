<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountantInviteMail;
use App\Models\AccountantClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AccountantController extends Controller
{
    /**
     * List all clients for the authenticated accountant.
     *
     * GET /api/v1/accountant/clients
     */
    public function clients(Request $request): JsonResponse
    {
        $accountant = $request->user();

        $relationships = AccountantClient::where('accountant_id', $accountant->id)
            ->whereIn('status', ['active', 'pending'])
            ->with(['client:id,name,email,company_name'])
            ->orderByDesc('created_at')
            ->get();

        $clients = $relationships->map(function (AccountantClient $rel) {
            $client = $rel->client;
            $hasBankConnected = $client->hasBankConnected();

            $transactionRange = null;
            $lastSync = null;

            if ($hasBankConnected) {
                $minMax = $client->transactions()
                    ->selectRaw('MIN(transaction_date) as earliest, MAX(transaction_date) as latest')
                    ->first();

                if ($minMax && $minMax->earliest) {
                    $transactionRange = [
                        'start' => $minMax->earliest,
                        'end' => $minMax->latest,
                    ];
                }

                $lastSync = $client->bankConnections()
                    ->whereNotNull('last_synced_at')
                    ->max('last_synced_at');
            }

            return [
                'id' => $rel->id,
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'company_name' => $client->company_name,
                ],
                'status' => $rel->status,
                'invited_by' => $rel->invited_by,
                'has_bank' => $hasBankConnected,
                'transaction_range' => $transactionRange,
                'last_sync' => $lastSync,
                'created_at' => $rel->created_at->toIso8601String(),
            ];
        });

        return response()->json(['clients' => $clients]);
    }

    /**
     * Get summary for a specific client.
     *
     * GET /api/v1/accountant/clients/{client}/summary
     */
    public function clientSummary(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        $this->verifyAccountantClientRelationship($accountant, $client);

        $accounts = $client->bankAccounts()->get(['id', 'name', 'type', 'current_balance', 'purpose']);

        $transactionRange = $client->transactions()
            ->selectRaw('MIN(transaction_date) as earliest, MAX(transaction_date) as latest, COUNT(*) as total')
            ->first();

        $lastSync = $client->bankConnections()
            ->whereNotNull('last_synced_at')
            ->max('last_synced_at');

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'company_name' => $client->company_name,
            ],
            'accounts' => $accounts,
            'transaction_count' => $transactionRange->total ?? 0,
            'transaction_range' => $transactionRange && $transactionRange->earliest ? [
                'start' => $transactionRange->earliest,
                'end' => $transactionRange->latest,
            ] : null,
            'last_sync' => $lastSync,
            'has_bank' => $client->hasBankConnected(),
            'has_email' => $client->hasEmailConnected(),
        ]);
    }

    /**
     * Invite a client by email.
     *
     * POST /api/v1/accountant/clients/invite
     */
    public function inviteClient(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $accountant = $request->user();
        $email = $request->input('email');

        // Can't invite yourself
        if ($accountant->email === $email) {
            return response()->json(['message' => 'You cannot add yourself as a client.'], 422);
        }

        $client = User::where('email', $email)->first();

        if (! $client) {
            return response()->json(['message' => 'No user found with that email address.'], 404);
        }

        // Check existing relationship
        $existing = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return response()->json(['message' => 'This user is already your client.'], 422);
            }
            if ($existing->status === 'pending') {
                return response()->json(['message' => 'An invitation is already pending for this user.'], 422);
            }
            // If revoked, allow re-invite
            $existing->update(['status' => 'pending', 'invited_by' => 'accountant']);
            Mail::to($client)->queue(new AccountantInviteMail($accountant, $client));

            return response()->json(['message' => 'Invitation sent successfully.']);
        }

        AccountantClient::create([
            'accountant_id' => $accountant->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'invited_by' => 'accountant',
        ]);

        Mail::to($client)->queue(new AccountantInviteMail($accountant, $client));

        return response()->json(['message' => 'Invitation sent successfully.']);
    }

    /**
     * Remove a client relationship.
     *
     * DELETE /api/v1/accountant/clients/{client}
     */
    public function removeClient(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        $relationship = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->first();

        if (! $relationship) {
            return response()->json(['message' => 'Client relationship not found.'], 404);
        }

        if ($relationship->status === 'pending') {
            $relationship->delete();

            return response()->json(['message' => 'Invitation rescinded.']);
        }

        $relationship->update(['status' => 'revoked']);

        return response()->json(['message' => 'Client removed.']);
    }

    /**
     * Resend invitation email to a pending client.
     *
     * POST /api/v1/accountant/clients/{client}/resend
     */
    public function resendInvite(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        $relationship = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->where('status', 'pending')
            ->first();

        if (! $relationship) {
            return response()->json(['message' => 'No pending invitation found for this client.'], 404);
        }

        Mail::to($client)->queue(new AccountantInviteMail($accountant, $client));

        return response()->json(['message' => 'Invitation email resent.']);
    }

    /**
     * Search for accountants (for personal users finding their accountant).
     *
     * GET /api/v1/accountant/search?q=
     */
    public function searchAccountants(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $query = $request->input('q');

        $accountants = User::where('user_type', 'accountant')
            ->where(function ($q) use ($query) {
                $q->where('name', 'ILIKE', "%{$query}%")
                    ->orWhere('company_name', 'ILIKE', "%{$query}%")
                    ->orWhere('email', 'ILIKE', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'email', 'company_name']);

        return response()->json(['accountants' => $accountants]);
    }

    /**
     * User adds an accountant (creates pending relationship).
     *
     * POST /api/v1/accountant/add
     */
    public function addAccountant(Request $request): JsonResponse
    {
        $request->validate([
            'accountant_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();
        $accountantId = $request->input('accountant_id');

        // Verify the target is actually an accountant
        $accountant = User::where('id', $accountantId)
            ->where('user_type', 'accountant')
            ->first();

        if (! $accountant) {
            return response()->json(['message' => 'Selected user is not an accountant.'], 422);
        }

        if ($user->id === $accountant->id) {
            return response()->json(['message' => 'You cannot add yourself as your accountant.'], 422);
        }

        // Check existing
        $existing = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return response()->json(['message' => 'This accountant is already linked to your account.'], 422);
            }
            if ($existing->status === 'pending') {
                return response()->json(['message' => 'A request is already pending.'], 422);
            }
            $existing->update(['status' => 'pending', 'invited_by' => 'client']);

            return response()->json(['message' => 'Request sent to accountant.']);
        }

        AccountantClient::create([
            'accountant_id' => $accountant->id,
            'client_id' => $user->id,
            'status' => 'pending',
            'invited_by' => 'client',
        ]);

        return response()->json(['message' => 'Request sent to accountant.']);
    }

    /**
     * User removes their accountant.
     *
     * DELETE /api/v1/accountant/{accountant}
     */
    public function removeAccountant(Request $request, User $accountant): JsonResponse
    {
        $user = $request->user();

        $relationship = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $user->id)
            ->first();

        if (! $relationship) {
            return response()->json(['message' => 'Accountant relationship not found.'], 404);
        }

        $relationship->update(['status' => 'revoked']);

        return response()->json(['message' => 'Accountant removed.']);
    }

    /**
     * List accountants for the current user.
     *
     * GET /api/v1/accountant/my-accountants
     */
    public function myAccountants(Request $request): JsonResponse
    {
        $user = $request->user();

        $relationships = AccountantClient::where('client_id', $user->id)
            ->where('status', 'active')
            ->with(['accountant:id,name,email,company_name'])
            ->orderByDesc('created_at')
            ->get();

        $accountants = $relationships->map(fn (AccountantClient $rel) => [
            'id' => $rel->id,
            'accountant' => [
                'id' => $rel->accountant->id,
                'name' => $rel->accountant->name,
                'email' => $rel->accountant->email,
                'company_name' => $rel->accountant->company_name,
            ],
            'status' => $rel->status,
            'invited_by' => $rel->invited_by,
            'created_at' => $rel->created_at->toIso8601String(),
        ]);

        return response()->json(['accountants' => $accountants]);
    }

    /**
     * Accept or decline a pending invite.
     *
     * POST /api/v1/accountant/invites/{invite}/respond
     */
    public function respondToInvite(Request $request, AccountantClient $invite): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:accept,decline',
        ]);

        $user = $request->user();

        // Determine which side the user is on
        $isAccountant = $invite->accountant_id === $user->id;
        $isClient = $invite->client_id === $user->id;

        if (! $isAccountant && ! $isClient) {
            return response()->json(['message' => 'This invitation does not belong to you.'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json(['message' => 'This invitation is no longer pending.'], 422);
        }

        // Only the receiving party can respond
        // If invited_by='accountant', client responds. If invited_by='client', accountant responds.
        $canRespond = ($invite->invited_by === 'accountant' && $isClient)
            || ($invite->invited_by === 'client' && $isAccountant);

        if (! $canRespond) {
            return response()->json(['message' => 'You cannot respond to your own invitation.'], 422);
        }

        $action = $request->input('action');

        if ($action === 'accept') {
            $invite->update(['status' => 'active']);

            return response()->json(['message' => 'Invitation accepted.']);
        }

        $invite->update(['status' => 'revoked']);

        return response()->json(['message' => 'Invitation declined.']);
    }

    /**
     * List pending invitations for the current user.
     *
     * GET /api/v1/accountant/invites
     */
    public function pendingInvites(Request $request): JsonResponse
    {
        $user = $request->user();

        $invites = AccountantClient::where('status', 'pending')
            ->where(function ($q) use ($user) {
                $q->where('client_id', $user->id)
                    ->orWhere('accountant_id', $user->id);
            })
            ->with(['accountant:id,name,email,company_name', 'client:id,name,email,company_name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AccountantClient $rel) => [
                'id' => $rel->id,
                'accountant' => [
                    'id' => $rel->accountant->id,
                    'name' => $rel->accountant->name,
                    'email' => $rel->accountant->email,
                    'company_name' => $rel->accountant->company_name,
                ],
                'client' => [
                    'id' => $rel->client->id,
                    'name' => $rel->client->name,
                    'email' => $rel->client->email,
                ],
                'invited_by' => $rel->invited_by,
                'can_respond' => ($rel->invited_by === 'accountant' && $rel->client_id === $user->id)
                    || ($rel->invited_by === 'client' && $rel->accountant_id === $user->id),
                'created_at' => $rel->created_at->toIso8601String(),
            ]);

        return response()->json(['invites' => $invites]);
    }

    /**
     * Get accountant's activity log.
     *
     * GET /api/v1/accountant/activity
     */
    public function activityLog(Request $request): JsonResponse
    {
        $accountant = $request->user();

        $logs = \App\Models\AccountantActivityLog::where('accountant_id', $accountant->id)
            ->with(['client:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'client' => [
                    'id' => $log->client->id,
                    'name' => $log->client->name,
                    'email' => $log->client->email,
                ],
                'action' => $log->action,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json(['activity' => $logs]);
    }

    // ─── Helpers ───

    protected function verifyAccountantClientRelationship(User $accountant, User $client): void
    {
        $exists = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            abort(403, 'You do not have access to this client.');
        }
    }
}
