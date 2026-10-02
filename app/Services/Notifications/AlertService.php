<?php

namespace App\Services\Notifications;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Module;
use App\Models\QuizFeedback;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\ClassPostPublished;
use App\Notifications\MasteryLevelChanged;
use App\Notifications\ModuleChangeRequested;
use App\Notifications\QuizFeedbackReceived;
use App\Notifications\StudentJoinedClass;
use App\Notifications\StudentNeedsIntervention;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Decides who receives which System Alert. Controllers call this after the
 * main action succeeds; an alert failure is logged and never breaks that action.
 */
class AlertService
{
    public function __construct(private AdaptiveLearningService $adaptive)
    {
    }

    /** New lesson/announcement → every enrolled student. */
    public function classPostPublished(ClassPost $post): void
    {
        $this->safely('class_post', function () use ($post) {
            $post->loadMissing('classRoom.students', 'author');
            $students = $post->classRoom?->students ?? collect();

            if ($students->isNotEmpty()) {
                Notification::send($students, new ClassPostPublished($post));
            }
        });
    }

    /** Student joined → the class's professor. */
    public function studentJoined(ClassRoom $class, User $student): void
    {
        $this->safely('student_joined', function () use ($class, $student) {
            $this->professorOf($class)?->notify(new StudentJoinedClass($class, $student));
        });
    }

    /** Collaborator asks for a module to be edited or deleted → the subject's owner. */
    public function moduleChangeRequested(Subject $subject, Module $module, User $requester, string $action, string $note): void
    {
        $this->safely('module_change_requested', function () use ($subject, $module, $requester, $action, $note) {
            User::find($subject->owner_id)?->notify(new ModuleChangeRequested($subject, $module, $requester, $action, $note));
        });
    }

    /** Quiz feedback submitted → the class's professor. */
    public function quizFeedbackReceived(QuizFeedback $feedback, ClassPost $post): void
    {
        $this->safely('quiz_feedback', function () use ($feedback, $post) {
            $this->professorOf($post->classRoom)?->notify(new QuizFeedbackReceived($feedback, $post));
        });
    }

    /**
     * After a graded quiz, alert on BKT level changes per competency:
     * - into Low (or a first assessment that lands in Low) → student + professor;
     * - into High → student only.
     * Staying at the same level sends nothing, so repeated quizzes don't spam.
     *
     * @param  array<int, array{competency_name: string, before: float, after: float, first_assessment?: bool}>  $masteryDeltas
     */
    public function quizGraded(User $student, ClassPost $post, array $masteryDeltas): void
    {
        $this->safely('quiz_graded', function () use ($student, $post, $masteryDeltas) {
            $professor = $this->professorOf($post->classRoom);

            foreach ($masteryDeltas as $delta) {
                $before = $this->adaptive->classify((float) $delta['before']);
                $after = $this->adaptive->classify((float) $delta['after']);
                $firstAssessment = (bool) ($delta['first_assessment'] ?? false);

                // The P(L0) prior already sits in Low, so a first assessment
                // that stays Low is still news worth surfacing.
                $enteredLow = $after === AdaptiveLearningService::LEVEL_LOW
                    && ($before !== AdaptiveLearningService::LEVEL_LOW || $firstAssessment);
                $enteredHigh = $after === AdaptiveLearningService::LEVEL_HIGH
                    && $before !== AdaptiveLearningService::LEVEL_HIGH;

                if ($enteredLow) {
                    $student->notify(new MasteryLevelChanged($post, $delta['competency_name'], (float) $delta['after'], $after));
                    $professor?->notify(new StudentNeedsIntervention($student, $post, $delta['competency_name'], (float) $delta['after']));
                } elseif ($enteredHigh) {
                    $student->notify(new MasteryLevelChanged($post, $delta['competency_name'], (float) $delta['after'], $after));
                }
            }
        });
    }

    /**
     * Always a full fresh model: callers may have eager-loaded the professor
     * with only a few columns (e.g. professor:id,name), which would hide the
     * recipient's notification preferences.
     */
    private function professorOf(?ClassRoom $class): ?User
    {
        return $class ? User::find($class->professor_id) : null;
    }

    private function safely(string $event, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::error('[alerts] Failed to send system alert.', [
                'event' => $event,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
