<?php

namespace App\Services\Quiz;

use App\Models\AnswerReviewRequest;
use App\Models\ClassPost;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Permission to review answers. When a lesson's "ask my permission before
 * students review their answers" setting is on, a student asks per attempt
 * and the teacher approves or denies. Until then the student sees only
 * their score — not which questions they got right, nor the answer key.
 * Once unlocked, the review shows the key if the teacher's "show correct
 * answers" setting is on.
 */
class AnswerReviewService
{
    /**
     * Ask (or ask again after a denial). An existing pending or approved
     * request is returned unchanged.
     */
    public function request(QuizAttempt $attempt, ClassPost $post): AnswerReviewRequest
    {
        $existing = $attempt->answerReviewRequest;

        if ($existing && $existing->status !== AnswerReviewRequest::STATUS_DENIED) {
            return $existing;
        }

        if ($existing) {
            $existing->update(['status' => AnswerReviewRequest::STATUS_PENDING, 'decided_by' => null, 'decided_at' => null]);

            return $existing;
        }

        return $attempt->answerReviewRequest()->create([
            'student_id' => $attempt->student_id,
            'class_post_id' => $post->id,
            'status' => AnswerReviewRequest::STATUS_PENDING,
        ]);
    }

    public function decide(AnswerReviewRequest $request, User $professor, bool $approved): AnswerReviewRequest
    {
        $request->update([
            'status' => $approved ? AnswerReviewRequest::STATUS_APPROVED : AnswerReviewRequest::STATUS_DENIED,
            'decided_by' => $professor->id,
            'decided_at' => now(),
        ]);

        return $request;
    }

    /**
     * Whether the student may open this attempt's question-by-question review.
     *
     * @param  array{review_requires_permission: bool}  $settings  the lesson's quiz settings
     */
    public function canReview(QuizAttempt $attempt, array $settings): bool
    {
        return ! $settings['review_requires_permission']
            || $attempt->answerReviewRequest?->status === AnswerReviewRequest::STATUS_APPROVED;
    }

    /**
     * The status the student sees: "approved" when no permission is needed.
     *
     * @param  array{review_requires_permission: bool}  $settings
     */
    public function visibleStatus(QuizAttempt $attempt, array $settings): ?string
    {
        return $settings['review_requires_permission'] ? $this->statusFor($attempt) : AnswerReviewRequest::STATUS_APPROVED;
    }

    /** null when nothing has been asked. */
    public function statusFor(QuizAttempt $attempt): ?string
    {
        return $attempt->answerReviewRequest?->status;
    }

    /**
     * A lesson's requests for the Quiz Setup page, pending first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listForPost(ClassPost $post): Collection
    {
        return AnswerReviewRequest::query()
            ->where('class_post_id', $post->id)
            ->with(['student:id,name', 'attempt:id,score_correct,score_total,submitted_at,focus_violations'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [AnswerReviewRequest::STATUS_PENDING])
            ->latest('updated_at')
            ->get()
            ->map(fn (AnswerReviewRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'student_name' => $r->student?->name,
                'score_correct' => $r->attempt?->score_correct,
                'score_total' => $r->attempt?->score_total,
                'focus_violations' => (int) ($r->attempt?->focus_violations ?? 0),
                'submitted_at' => $r->attempt?->submitted_at?->toIso8601String(),
                'requested_at' => $r->updated_at?->toIso8601String(),
            ]);
    }

    public function pendingCount(ClassPost $post): int
    {
        return AnswerReviewRequest::where('class_post_id', $post->id)
            ->where('status', AnswerReviewRequest::STATUS_PENDING)
            ->count();
    }
}
