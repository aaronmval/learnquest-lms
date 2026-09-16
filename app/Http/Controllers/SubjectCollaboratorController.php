<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteCollaboratorRequest;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubjectCollaboratorController extends Controller
{
    /**
     * Invite a collaborator by email. Matches an existing professor account
     * instantly — there's no pending-invite/email-notification system.
     */
    public function store(InviteCollaboratorRequest $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $email = $request->validated('email');
        $invitee = User::where('email', $email)->where('role', 'professor')->first();

        if (! $invitee) {
            throw ValidationException::withMessages([
                'email' => 'No professor account found with that email.',
            ]);
        }

        if ($invitee->id === $subject->owner_id || $subject->collaborators()->where('user_id', $invitee->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'That professor is already part of this subject.',
            ]);
        }

        $subject->collaborators()->attach($invitee->id, ['invited_by' => $request->user()->id]);

        return response()->json($invitee->only(['id', 'name', 'email']), 201);
    }

    /**
     * Remove a collaborator. The original owner can never be removed here.
     */
    public function destroy(Request $request, Subject $subject, User $user): Response
    {
        abort_unless($subject->isManagedBy($request->user()), 404);
        abort_if($user->id === $subject->owner_id, 403);

        $subject->collaborators()->detach($user->id);

        return response()->noContent();
    }
}
