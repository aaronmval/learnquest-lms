<?php

namespace App\Services\Account;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Module;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently deletes a user's own account and everything that belongs
 * only to them. What other people still depend on is handed over instead:
 * a subject another professor still uses gets a new owner, and modules or
 * posts the user contributed to someone else's subject or class stay there.
 */
class AccountDeletionService
{
    public function delete(User $user): void
    {
        $files = DB::transaction(function () use ($user) {
            $files = array_filter([$user->avatar_path]);

            // Their classes go first: the quizzes in them use the competencies
            // of the subjects deleted below (posts, quizzes and attempts cascade).
            $classIds = ClassRoom::where('professor_id', $user->id)->pluck('id');
            $files = array_merge($files, ClassPost::whereIn('class_id', $classIds)
                ->whereNotNull('attachment_path')->pluck('attachment_path')->all());
            ClassRoom::whereIn('id', $classIds)->delete();

            foreach (Subject::where('owner_id', $user->id)->get() as $subject) {
                $heirId = $this->heirFor($subject, $user);

                if ($heirId !== null) {
                    $subject->update(['owner_id' => $heirId]);
                    $subject->collaborators()->detach($heirId);
                } else {
                    $files = array_merge($files, $subject->modules()->pluck('file_path')->all());
                    $subject->delete(); // modules and competencies cascade
                }
            }

            // What they contributed to subjects and classes that live on now
            // belongs to that subject's owner / that class's professor.
            foreach (Module::where('uploaded_by', $user->id)->with('subject:id,owner_id')->get() as $module) {
                $module->update(['uploaded_by' => $module->subject->owner_id]);
            }

            foreach (ClassPost::where('author_id', $user->id)->with('classRoom:id,professor_id')->get() as $post) {
                $post->update(['author_id' => $post->classRoom->professor_id]);
            }

            DB::table('subject_collaborators')->where('user_id', $user->id)->delete();
            DB::table('subject_collaborators')
                ->where('invited_by', $user->id)
                ->update(['invited_by' => DB::raw('(select owner_id from subjects where subjects.id = subject_collaborators.subject_id)')]);

            // Rows with no foreign key to the user.
            $user->notifications()->delete();
            DB::table('otp_codes')->where('email', $user->email)->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            // Enrollments, quiz attempts, mastery, QuestAI chats, quiz
            // settings and reviews cascade from the user row.
            $user->delete();

            return $files;
        });

        $this->deleteFiles($files, $user->id);
    }

    /**
     * Who takes over a subject the user owns: the collaborator who joined
     * first, else another professor whose class is still part of it. Null
     * means nobody else uses the subject, so it is deleted.
     */
    private function heirFor(Subject $subject, User $user): ?int
    {
        $collaboratorId = DB::table('subject_collaborators')
            ->where('subject_id', $subject->id)
            ->where('user_id', '!=', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('user_id');

        return $collaboratorId
            ?? ClassRoom::where('subject_id', $subject->id)
                ->where('professor_id', '!=', $user->id)
                ->orderBy('id')
                ->value('professor_id');
    }

    /** Stored files are removed once the database delete has committed. */
    private function deleteFiles(array $paths, int $userId): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                Storage::disk()->delete($path);
            } catch (Throwable $e) {
                Log::warning('[account] Could not delete a file of a deleted account.', [
                    'user_id' => $userId,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
