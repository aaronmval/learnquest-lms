<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use App\Services\Documents\LessonContentService;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * QuestAI tutoring chat. Grounds Llama in the teacher's lesson summaries
 * and retrieved PDF excerpts, and tells it the student's BKT mastery so it
 * can adapt explanations. BKT computes mastery; Llama only writes replies.
 */
class QuestAiService
{
    private const HISTORY_MESSAGES = 10;

    private const MAX_LESSONS_IN_CONTEXT = 10;

    private const MAX_REPLY_CHARS = 4000;

    private const MAX_CLASS_POSTS = 10;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private LessonContentService $lessonContent,
        private BayesianKnowledgeTracingService $bkt,
        private AdaptiveLearningService $adaptive,
    ) {
    }

    /**
     * Generate and store the assistant's reply to $userMessage.
     *
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    public function reply(User $student, AiConversation $conversation, AiMessage $userMessage): AiMessage
    {
        $classes = $this->classesInScope($student, $conversation);
        $competencies = $this->competenciesFor($classes);
        $profile = $this->adaptive->competencyProfile($student, $competencies);
        $overall = $this->overallMastery($student, $competencies, $profile);

        $history = $conversation->messages()
            ->where('id', '<', $userMessage->id)
            ->latest('id')
            ->take(self::HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $lessonPosts = ClassPost::whereIn('class_id', $classes->pluck('id'))
            ->where('type', 'lesson')
            ->with('summary')
            ->latest('id')
            ->get();

        // Short follow-ups ("B", "why?") carry little signal on their own,
        // so the previous question also feeds retrieval.
        $previousQuestion = $history->where('role', AiMessage::ROLE_USER)->last()?->content ?? '';
        $excerpts = $this->lessonContent->retrieve($lessonPosts, $userMessage->content.' '.$previousQuestion);

        $messages = $this->prompts->questAiTutorMessages(
            $this->scopeLabel($conversation, $classes),
            $this->lessonContent->lessonSummaries($lessonPosts, self::MAX_LESSONS_IN_CONTEXT),
            $this->lessonContent->recentClassPosts($classes, self::MAX_CLASS_POSTS),
            $excerpts,
            $overall,
            $profile,
            $this->adaptive->weaknesses($profile),
            $history->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => $m->content])->all(),
            $userMessage->content,
        );

        $result = $this->llama->chat($messages);
        $content = trim($result['content']);

        if ($content === '') {
            throw new InvalidAiResponseException('AI tutor returned an empty reply.');
        }

        $reply = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => Str::limit($content, self::MAX_REPLY_CHARS),
            'model' => $result['model'],
            'source_post_ids' => array_values(array_unique(array_column($excerpts, 'post_id'))),
        ]);

        $conversation->update(['last_message_at' => now()]);

        Log::channel('ai')->info('[questai] Reply generated.', [
            'conversation_id' => $conversation->id,
            'message_id' => $reply->id,
            'model' => $result['model'],
            'excerpt_count' => count($excerpts),
            'lesson_count' => $lessonPosts->count(),
        ]);

        return $reply;
    }

    /**
     * Enrolled classes with their BKT mastery overview, for the page's
     * class chips, personalization banner and insights card.
     *
     * @return array{classes: array<int, array<string, mixed>>}
     */
    public function context(User $student): array
    {
        $classes = $student->enrolledClasses()
            ->with('parentSubject.competencies')
            ->orderByDesc('class_enrollments.created_at')
            ->get();

        return [
            'classes' => $classes->map(function (ClassRoom $class) use ($student) {
                $competencies = $class->parentSubject?->competencies ?? collect();
                $profile = $this->adaptive->competencyProfile($student, $competencies);

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'subject' => $class->subject ?: $class->parentSubject?->name,
                    'section' => $class->section,
                    'overall' => $this->overallMastery($student, $competencies, $profile),
                    'weaknesses' => array_map(
                        fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'mastery' => $c['mastery'], 'level' => $c['level']],
                        array_slice($this->adaptive->weaknesses($profile), 0, 3),
                    ),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return Collection<int, ClassRoom>
     */
    private function classesInScope(User $student, AiConversation $conversation): Collection
    {
        $query = $student->enrolledClasses()->with('parentSubject.competencies');

        if ($conversation->class_id !== null) {
            $query->where('classes.id', $conversation->class_id);
        }

        return $query->get();
    }

    private function competenciesFor(Collection $classes): Collection
    {
        return $classes
            ->flatMap(fn (ClassRoom $class) => $class->parentSubject?->competencies ?? collect())
            ->unique('id')
            ->values();
    }

    /**
     * Overall BKT mastery, or null when no competency has been assessed yet
     * (so the page and prompt say "no quiz data" instead of showing the prior).
     */
    private function overallMastery(User $student, Collection $competencies, array $profile): ?array
    {
        $hasEvidence = collect($profile)->contains(fn ($c) => $c['assessed']);

        if (! $hasEvidence) {
            return null;
        }

        $mastery = (float) $this->bkt->averageMasteryForCompetencies($student, $competencies);

        return ['mastery' => $mastery, 'level' => $this->adaptive->classify($mastery)];
    }

    private function scopeLabel(AiConversation $conversation, Collection $classes): string
    {
        $labels = $classes->map(fn (ClassRoom $c) => $c->subject ?: $c->name)->filter()->unique()->implode(', ');

        if ($conversation->class_id !== null) {
            return "the student's class: {$labels}";
        }

        return $labels !== '' ? "all of the student's classes: {$labels}" : 'the student is not enrolled in any class yet';
    }
}
