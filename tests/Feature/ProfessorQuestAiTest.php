<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\LessonSummary;
use App\Models\Quiz;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProfessorQuestAiTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private int $classId;

    private int $weakId;

    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn(
            "Moles and molar mass.\nThe mole ratio from a balanced equation converts moles of reactant to moles of product."
        );
        $this->app->instance(PdfTextExtractorService::class, $extractor);

        $this->professor = User::factory()->create(['role' => 'professor']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $this->weakId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Stoichiometry'])->json('id');

        $post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Mole Concept',
            'attachment_path' => 'class-posts/mole.pdf',
            'attachment_name' => 'mole.pdf',
        ]);
        $this->postId = $post->id;

        LessonSummary::create([
            'class_post_id' => $post->id,
            'overview' => 'The mole links particle counts to mass.',
            'key_points' => ['Molar mass is grams per mole'],
            'model' => 'llama',
            'generated_at' => now(),
        ]);
    }

    /** Enroll a student who answers every Stoichiometry question wrong. */
    private function strugglingStudent(string $name = 'Alice Reyes'): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $quiz = Quiz::create(['class_post_id' => $this->postId, 'model' => 'llama', 'generated_at' => now()]);

        $answers = [];
        foreach (range(0, 2) as $i) {
            $question = $quiz->questions()->create([
                'competency_id' => $this->weakId,
                'question_text' => "Question {$i}",
                'choices' => ['Right', 'Wrong', 'Nope', 'No'],
                'correct_answer' => 'Right',
                'explanation' => 'Because.',
                'difficulty' => 'easy',
                'order_index' => $i,
            ]);
            $answers[] = ['question_id' => $question->id, 'selected_index' => 1];
        }

        $this->actingAs($student)
            ->postJson("/student/classes/{$this->classId}/posts/{$this->postId}/quiz/attempts", ['answers' => $answers])
            ->assertCreated();

        return $student;
    }

    private function mockLlama(?callable $assertMessages = null): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')
            ->once()
            // Short limits so a slow Llama hands over to DeepSeek quickly.
            ->withArgs(fn (array $messages, array $options) => $options['timeout'] === 25
                && $options['max_retries'] === 0
                && $options['fallback_timeout'] === 40
                && $options['last_resort_timeout'] === 35
                && ($assertMessages === null || $assertMessages($messages)))
            ->andReturn(['content' => 'Try a **mole-ratio relay**:\n- Pair students', 'model' => 'llama-3.3-70b-instruct']);
        $this->app->instance(LlamaService::class, $llama);
    }

    public function test_context_lists_managed_sections_with_class_mastery(): void
    {
        $this->strugglingStudent();

        $otherProfessor = User::factory()->create(['role' => 'professor']);
        $otherSubject = $this->actingAs($otherProfessor)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $this->actingAs($otherProfessor)->postJson("/professor/subjects/{$otherSubject}/sections", ['name' => 'Class B', 'section' => 'STEM B']);

        $res = $this->actingAs($this->professor)->getJson('/professor/questai/context')->assertOk();

        $res->assertJsonPath('professor.name', $this->professor->name);
        $res->assertJsonCount(1, 'classes');
        $res->assertJsonPath('classes.0.id', $this->classId);
        $res->assertJsonPath('classes.0.label', 'Chemistry - STEM A');
        $res->assertJsonPath('classes.0.assessed', 1);
        $res->assertJsonPath('classes.0.low', 1);
        $res->assertJsonPath('classes.0.weaknesses.0.name', 'Stoichiometry');
    }

    public function test_message_is_grounded_in_bkt_and_lessons_without_student_names(): void
    {
        $this->strugglingStudent('Alice Reyes');

        $this->mockLlama(function (array $messages) {
            $system = $messages[0]['content'];

            return str_contains($system, 'QuestAI Coach')
                && str_contains($system, 'CLASS MASTERY by competency (BKT)')
                && str_contains($system, 'Stoichiometry')
                && str_contains($system, '1 at low mastery')
                && str_contains($system, 'The mole links particle counts to mass.')
                && str_contains($system, 'mole ratio from a balanced equation')
                && ! str_contains($system, 'Alice')
                && end($messages)['content'] === 'How should I re-teach mole ratio problems?';
        });

        $res = $this->actingAs($this->professor)->postJson('/professor/questai/messages', [
            'message' => 'How should I re-teach mole ratio problems?',
            'class_id' => $this->classId,
        ])->assertCreated();

        $res->assertJsonPath('reply.role', 'assistant');
        $conversation = AiConversation::findOrFail($res->json('conversation.id'));
        $this->assertSame($this->professor->id, $conversation->professor_id);
        $this->assertNull($conversation->student_id);
        $this->assertSame($this->classId, $conversation->class_id);
        $this->assertSame(2, $conversation->messages()->count());

        $this->actingAs($this->professor)->getJson('/professor/questai/conversations')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'How should I re-teach mole ratio problems?');
    }

    public function test_follow_up_replays_history_in_same_conversation(): void
    {
        $conversation = AiConversation::create([
            'professor_id' => $this->professor->id,
            'title' => 'Warm-up',
            'last_message_at' => now(),
        ]);
        $conversation->messages()->create(['role' => AiMessage::ROLE_USER, 'content' => 'Suggest a warm-up']);
        $conversation->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Try a predict-and-explain demo.']);

        $this->mockLlama(fn (array $messages) => count($messages) === 4
            && $messages[1]['content'] === 'Suggest a warm-up'
            && $messages[2]['content'] === 'Try a predict-and-explain demo.'
            && str_contains($messages[0]['content'], 'CLASS MASTERY: no quiz data yet.'));

        $this->actingAs($this->professor)->postJson('/professor/questai/messages', [
            'message' => 'Make it shorter',
            'conversation_id' => $conversation->id,
        ])->assertCreated()->assertJsonPath('conversation.id', $conversation->id);
    }

    public function test_cannot_use_another_professors_conversation_or_unmanaged_class(): void
    {
        $otherProfessor = User::factory()->create(['role' => 'professor']);
        $otherSubject = $this->actingAs($otherProfessor)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $otherClassId = $this->actingAs($otherProfessor)
            ->postJson("/professor/subjects/{$otherSubject}/sections", ['name' => 'Class B', 'section' => 'STEM B'])->json('id');
        $foreign = AiConversation::create(['professor_id' => $otherProfessor->id, 'title' => 'Theirs', 'last_message_at' => now()]);
        $reply = $foreign->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Hi']);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->getJson("/professor/questai/conversations/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->professor)->postJson('/professor/questai/messages', [
            'message' => 'Hi', 'conversation_id' => $foreign->id,
        ])->assertNotFound();
        $this->actingAs($this->professor)->postJson('/professor/questai/messages', [
            'message' => 'Hi', 'class_id' => $otherClassId,
        ])->assertNotFound();
        $this->actingAs($this->professor)->postJson("/professor/questai/messages/{$reply->id}/feedback", ['helpful' => true])
            ->assertNotFound();
    }

    public function test_students_cannot_reach_the_coach_and_cannot_see_professor_chats(): void
    {
        $student = $this->strugglingStudent();
        $conversation = AiConversation::create(['professor_id' => $this->professor->id, 'title' => 'Mine', 'last_message_at' => now()]);

        $this->actingAs($student)->getJson('/professor/questai/context')->assertRedirect('/login');
        $this->actingAs($student)->postJson('/professor/questai/messages', ['message' => 'Hi'])->assertRedirect('/login');
        $this->actingAs($student)->getJson("/student/questai/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($student)->getJson('/student/questai/conversations')->assertOk()->assertJsonCount(0);
    }

    public function test_ai_failure_keeps_the_question(): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andThrow(new LlamaApiException('Routeway down'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($this->professor)->postJson('/professor/questai/messages', ['message' => 'Hello?'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'QuestAI is temporarily unavailable. Please try again in a moment.');

        $conversation = AiConversation::findOrFail($res->json('conversation.id'));
        $this->assertSame(['Hello?'], $conversation->messages()->pluck('content')->all());
    }

    public function test_feedback_is_saved_on_assistant_replies(): void
    {
        $this->mockLlama();

        $replyId = $this->actingAs($this->professor)->postJson('/professor/questai/messages', ['message' => 'Hi'])
            ->assertCreated()->json('reply.id');

        $this->actingAs($this->professor)->postJson("/professor/questai/messages/{$replyId}/feedback", ['helpful' => false])
            ->assertOk()->assertJsonPath('feedback', -1);
    }
}
