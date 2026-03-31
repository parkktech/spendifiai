<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateHouseholdInvitationRequest;
use App\Mail\HouseholdInviteMail;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Services\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class HouseholdController extends Controller
{
    public function __construct(
        private HouseholdService $householdService,
    ) {}

    /**
     * Get current household info with members.
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->household_id) {
            return response()->json([
                'household' => null,
                'members' => [],
                'invitations' => [],
            ]);
        }

        $household = Household::with(['members:id,household_id,name,email,household_role,created_at'])->find($user->household_id);

        $invitations = HouseholdInvitation::where('household_id', $user->household_id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get(['id', 'email', 'status', 'expires_at', 'created_at']);

        return response()->json([
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'role' => $user->household_role,
                'member_count' => $household->members->count(),
                'created_at' => $household->created_at,
            ],
            'members' => $household->members->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->household_role,
                'joined_at' => $m->created_at,
            ]),
            'invitations' => $invitations,
        ]);
    }

    /**
     * Create a new household.
     */
    public function create(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->household_id) {
            return response()->json(['message' => 'You are already in a household.'], 422);
        }

        $request->validate(['name' => 'nullable|string|max:100']);

        $household = $this->householdService->createHousehold(
            $user,
            $request->input('name', 'My Household')
        );

        return response()->json([
            'message' => 'Household created.',
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
            ],
        ], 201);
    }

    /**
     * Rename household (owner only).
     */
    public function update(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (! $user->household_id || $user->household_role !== 'owner') {
            return response()->json(['message' => 'Only the household owner can rename it.'], 403);
        }

        $request->validate(['name' => 'required|string|max:100']);

        Household::where('id', $user->household_id)->update(['name' => $request->input('name')]);

        return response()->json(['message' => 'Household renamed.']);
    }

    /**
     * Generate an invitation link.
     */
    public function invite(CreateHouseholdInvitationRequest $request): JsonResponse
    {
        $user = auth()->user();

        $invitation = $this->householdService->createInvitation(
            $user,
            $request->input('email')
        );

        // Send email if provided
        if ($invitation->email) {
            Mail::to($invitation->email)->queue(new HouseholdInviteMail($user, $invitation));
        }

        // Return the token (normally hidden) so user can share the link
        $inviteUrl = config('app.url').'/household/join/'.$invitation->token;

        return response()->json([
            'message' => 'Invitation created.',
            'invite_url' => $inviteUrl,
            'expires_at' => $invitation->expires_at,
        ], 201);
    }

    /**
     * Validate an invitation token (public — no auth required).
     */
    public function validateInvitation(string $token): JsonResponse
    {
        $invitation = HouseholdInvitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json([
                'valid' => false,
                'message' => 'This invitation is invalid or has expired.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'household_name' => $invitation->household->name,
            'invited_by' => $invitation->invitedBy->name,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    /**
     * Accept an invitation and join the household.
     */
    public function acceptInvitation(string $token): JsonResponse
    {
        $user = auth()->user();
        $invitation = HouseholdInvitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invalid invitation.'], 404);
        }

        try {
            $this->householdService->acceptInvitation($user, $invitation);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'You have joined the household.',
            'household' => [
                'id' => $invitation->household_id,
                'name' => $invitation->household->name,
            ],
        ]);
    }

    /**
     * Revoke a pending invitation.
     */
    public function revokeInvitation(string $token): JsonResponse
    {
        $user = auth()->user();

        $invitation = HouseholdInvitation::where('token', $token)
            ->where('household_id', $user->household_id)
            ->where('status', 'pending')
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        $invitation->update(['status' => 'revoked']);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * Owner removes a member.
     */
    public function removeMember(User $user): JsonResponse
    {
        $owner = auth()->user();

        try {
            $this->householdService->removeMember($owner, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Member removed.']);
    }

    /**
     * Member leaves the household.
     */
    public function leave(): JsonResponse
    {
        $user = auth()->user();

        try {
            $this->householdService->leaveHousehold($user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'You have left the household.']);
    }
}
