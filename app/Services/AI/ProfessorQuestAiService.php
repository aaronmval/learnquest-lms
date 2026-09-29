<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\User;
use App\Services\Documents\LessonContentService;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\ProfessorAnalyticsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * QuestAI Coach for professors. Grounds Llama in the professor's lesson
 * materials and the class-level BKT mastery from ProfessorAnalyticsService.
 * BKT computes mastery; Llama only writes teaching advice. Only aggregate
 * figures are sent to the model — never student names.
 */
class ProfessorQuestAiService
{
    private const HISTORY_MESSAGES = 10;

    private const MAX_LESSONS_IN_CONTEXT = 10;

    private const MAX_CLASS_POSTS = 10;

    private const MAX_REPLY_CHARS = 4000;

    private const MAX_WEAKNESSES = 3;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private LessonContentService $lessonContent,
        private ProfessorAnalyticsService $analytics,
        private ClassInsightService $classInsights,
    ) {
    }

    /**
     * Generate and store the assistant's reply to $userMessage.
     *
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    public function reply(User $professor, AiConversation $conversation, AiMessage $userMessage): AiMessage
    {
        $classes = $this->classesInScope($professor, $conversation);
        $dashboard = $this->analytics->dashboard($professor, $conversation->class_id);

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

        // Short follow-ups ("make it shorter") carry little signal on their
        // own, so the previous question also feeds retrieval.
        $previousQuestion = $history->where('role', AiMessage::ROLE_USER)->last()?->content ?? '';
        $excerpts = $this->lessonContent->retrieve($lessonPosts, $userMessage->content.' '.$previousQuestion);

        $messages = $this->prompts->professorCoachMessages(
            $this->scopeLabel($conversation, $classes),
            $this->classInsights->summary($dashboard),
            $dashboard['competencies'],
            $this->lessonContent->lessonSummaries($lessonPosts, self::MAX_LESSONS_IN_CONTEXT),
            $this->lessonContent->recentClassPosts($classes, self::MAX_CLASS_POSTS),
            $excerpts,
            $history->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => $m->content])->all(),
            $userMessage->content,
        );

        // Per-call limits: a slow or failing Llama hands over to the
        // fallback model (DeepSeek) quickly while the professor waits.
        $limits = array_map('intval', (array) config('services.routeway.questai_coach', []));
        $result = $this->llama->chat($messages, $limits);
        $content = trim($result['content']);

        if ($content === '') {
            throw new InvalidAiResponseException('AI coach returned an empty reply.');
        }

        $reply = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => Str::limit($content, self::MAX_REPLY_CHARS),
            'model' => $result['model'],
            'source_post_ids' => array_values(array_unique(array_column($excerpts, 'post_id'))),
        ]);

        $conversation->update(['last_message_at' => now()]);

        Log::channel('ai')->info('[questai-coach] Reply generated.', [
            'conversation_id' => $conversation->id,
            'message_id' => $reply->id,
            'model' => $result['model'],
            'excerpt_count' => count($excerpts),
            'lesson_count' => $lessonPosts->count(),
        ]);

        return $reply;
    }

    /**
     * Managed sections with their class-level BKT overview, for the page's
     * greeting, section chips and personalization banner.
     *
     * @return array{professor: array{name: string}, classes: array<int, array<string, mixed>>}
     */
    public function context(User $professor): array
    {
        $classes = $this->analytics->managedClasses($professor);

        return [
            'professor' => ['name' => $professor->name],
            'classes' => $classes->map(function (ClassRoom $class) use ($professor) {
                $dashboard = $this->analytics->dashboard($professor, $class->id);
                $summary = $this->classInsights->summary($dashboard);

                return [
                    'id' => $class->id,
                    'label' => $this->analytics->sectionLabel($class),
                    'subject' => $class->subject ?: $class->parentSubject?->name,
                    'section' => $class->section,
                    'students' => $summary['students'],
                    'assessed' => $summary['assessed'],
                    'low' => $summary['low'],
                    'weaknesses' => $this->weaknesses($dashboard['competencies']),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Assessed competencies below high class mastery, weakest first.
     *
     * @return array<int, array{id:int, name:string, mastery:float, level:string}>
     */
    private function weaknesses(array $competencies): array
    {
        return collect($competencies)
            ->filter(fn ($c) => $c['assessed_students'] > 0 && $c['level'] !== AdaptiveLearningService::LEVEL_HIGH)
            ->sortBy('mastery')
            ->take(self::MAX_WEAKNESSES)
            ->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'mastery' => $c['mastery'], 'level' => $c['level']])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ClassRoom>
     */
    private function classesInScope(User $professor, AiConversation $conversation): Collection
    {
        $classes = $this->analytics->managedClasses($professor);

        return $conversation->class_id !== null
            ? $classes->where('id', $conversation->class_id)->values()
            : $classes;
    }

    private function scopeLabel(AiConversation $conversation, Collection $classes): string
    {
        $labels = $classes->map(fn (ClassRoom $c) => $this->analytics->sectionLabel($c))->filter()->unique()->implode(', ');

        if ($conversation->class_id !== null) {
            return "the teacher's section: {$labels}";
        }

        return $labels !== '' ? "all of the teacher's sections: {$labels}" : 'the teacher has no active sections yet';
    }
}
