<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Account settings shared by students and professors. Every action applies
 * only to the authenticated user.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update(['name' => trim($validated['name'])]);

        return response()->json($this->payload($user));
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = User::ALERT_PREFERENCES[$user->role] ?? [];

        $unknown = array_diff(array_keys($request->all()), $allowed);
        abort_if(! empty($unknown), 422, 'Unknown notification setting: '.implode(', ', $unknown));

        $validated = $request->validate(
            collect($allowed)->mapWithKeys(fn ($key) => [$key => ['sometimes', 'boolean']])->all()
        );

        $preferences = array_merge($user->notification_preferences ?? [], array_map('boolval', $validated));
        $user->update(['notification_preferences' => $preferences]);

        return response()->json($this->payload($user));
    }

    /**
     * Save any subset of the General tab's settings for the user's role.
     */
    public function updateGeneral(Request $request): JsonResponse
    {
        $user = $request->user();
        $options = User::generalPreferenceOptions($user->role);

        $unknown = array_diff(array_keys($request->all()), array_keys($options));
        abort_if(! empty($unknown), 422, 'Unknown general setting: '.implode(', ', $unknown));

        $validated = $request->validate(
            collect($options)->map(fn (array $option) => [
                'sometimes',
                is_bool($option['values'][0]) ? 'boolean' : Rule::in($option['values']),
            ])->all()
        );

        // Store each value in its declared type (e.g. "12" → 12, 1 → true).
        foreach ($validated as $key => $value) {
            $type = $options[$key]['values'][0];
            $validated[$key] = match (true) {
                is_bool($type) => (bool) $value,
                is_int($type) => (int) $value,
                default => $value,
            };
        }

        $user->update(['general_preferences' => array_merge($user->general_preferences ?? [], $validated)]);

        return response()->json($this->payload($user));
    }

    /**
     * Put every General setting back to its default.
     */
    public function resetGeneral(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['general_preferences' => null]);

        return response()->json($this->payload($user));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.different' => 'Your new password must be different from your current password.',
        ]);

        // The model's "hashed" cast hashes it, same as registration.
        $request->user()->update(['password' => $request->input('password')]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'photo.image' => 'Please choose an image file.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        $path = $request->file('photo')->store("avatars/{$user->id}");
        $user->update(['avatar_path' => $path]);

        if ($previous && $previous !== $path) {
            Storage::disk()->delete($previous);
        }

        return response()->json($this->payload($user));
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk()->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json($this->payload($user));
    }

    /**
     * Stream the authenticated user's own photo from private storage.
     */
    public function showAvatar(Request $request): StreamedResponse
    {
        $path = $request->user()->avatar_path;

        abort_unless($path && Storage::disk()->exists($path), 404);

        return Storage::disk()->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function payload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar_url' => $user->avatarUrl(),
            'notification_preferences' => $user->alertPreferences(),
            'general_preferences' => $user->generalPreferences(),
        ];
    }
}
