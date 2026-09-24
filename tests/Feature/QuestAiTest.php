<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\LessonSummary;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class QuestAiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private int $classId;

    private int $weakCompetencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn(
            "Moles and molar mass.\nThe mole ratio from a balanced equation converts moles of reactant to moles of product."
        );
        $this->app->instance(PdfTextExtractorService::class, $extractor);

        $professor = User::factory()->create(['role' => 'professor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $this->weakCompetencyId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Stoichiometry',
        ])->json('id');

        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($this->student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Mole Concept',
            'attachment_path' => 'class-posts/mole.pdf',
            'attachment_name' => 'mole.pdf',
        ]);

        LessonSummary::create([
            'class_post_id' => $post->id,
            'overview' => 'The mole links particle counts to mass.',
            'key_points' => ['Molar mass is grams per mole'],
            'model' => 'llama',
            'generated_at' => now(),
        ]);

        StudentMastery::create([
            'student_id' => $this->student->id,
            'competency_id' => $this->weakCompetencyId,
            'initial_mastery' => 0.30,
            'current_mastery' => 0.25,
            'p_l0' => 0.30,
            'p_t' => 0.15,
            'p_g' => 0.25,
            'p_s' => 0.10,
            'observations_count' => 4,
        ]);
    }

    private function mockLlama(?callable $assertMessages = null, string $reply = 'Here is a **clear** explanation.'): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andReturnUsing(function (array $messages) use ($assertMessages, $reply) {
            if ($assertMessages) {
                $assertMessages($messages);
            }

            return ['content' => $reply, 'model' => 'llama-3.3-70b-instruct'];
        });
        $this->app->instance(LlamaService::class, $llama);
    }

    public function test_context_lists_enrolled_classes_with_bkt_weaknesses(): void
    {
        $res = $this->actingAs($this->student)->getJson('/student/questai/context');

        $res->assertOk();
        $res->assertJsonCount(1, 'classes');
        $res->assertJsonPath('classes.0.id', $this->classId);
        $res->assertJsonPath('classes.0.overall.level', 'low');
        $res->assertJsonPath('classes.0.weaknesses.0.name', 'Stoichiometry');
    }

    public function test_message_is_grounded_in_lessons_and_mastery_and_saved(): void
    {
        $this->mockLlama(function (array $messages) {
            $system = $messages[0]['content'];
            $this->assertStringContainsString('The mole links particle counts to mass.', $system);
            $this->assertStringContainsString('mole ratio from a balanced equation', $system);
            $this->assertStringContainsString('WEAK AREAS: Stoichiometry (25%)', $system);
            $this->assertSame('How do I use the mole ratio?', end($messages)['content']);
        });

        $res = $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'How do I use the mole ratio?',
            'class_id' => $this->classId,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('conversation.class_id', $this->classId);
        $res->assertJsonPath('conversation.title', 'How do I use the mole ratio?');
        $res->assertJsonPath('reply.role', 'assistant');
        $res->assertJsonPath('reply.content', 'Here is a **clear** explanation.');

        $this->assertDatabaseCount('ai_conversations', 1);
        $this->assertDatabaseCount('ai_messages', 2);
        $reply = AiMessage::where('role', 'assistant')->first();
        $this->assertCount(1, $reply->source_post_ids);
    }

    public function test_class_notes_summary_gets_announcements_and_unsummarized_lesson_text(): void
    {
        $professorId = ClassPost::first()->author_id;

        ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $professorId,
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Lab safety reminder',
            'body' => 'Bring goggles on Friday.',
            'checklist' => ['Lab gown', 'Goggles'],
        ]);

        // A lesson with no stored AI summary falls back to its extracted text.
        ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $professorId,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Molar Mass',
            'attachment_path' => 'class-posts/molar.pdf',
            'attachment_name' => 'molar.pdf',
        ]);

        $this->mockLlama(function (array $messages) {
            $system = $messages[0]['content'];
            $this->assertStringContainsString('CLASS POSTS (newest first):', $system);
            $this->assertStringContainsString('announcement: Lab safety reminder — Bring goggles on Friday.', $system);
            $this->assertStringContainsString('Checklist: Lab gown; Goggles', $system);
            $this->assertStringContainsString('Opening of the lesson file: Moles and molar mass.', $system);
            $this->assertStringContainsString('If asked to summarize the class notes', $system);
        });

        $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'Summarize the class notes for Chemistry',
            'class_id' => $this->classId,
        ])->assertCreated();
    }

    public function test_follow_up_includes_previous_turns(): void
    {
        $this->mockLlama();
        $conversationId = $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'Give me a practice question',
        ])->json('conversation.id');

        $this->mockLlama(function (array $messages) {
            $roles = array_column($messages, 'role');
            $this->assertSame(['system', 'user', 'assistant', 'user'], $roles);
            $this->assertSame('Give me a practice question', $messages[1]['content']);
        });

        $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'B',
            'conversation_id' => $conversationId,
        ])->assertCreated();

        $this->assertDatabaseCount('ai_conversations', 1);
        $this->assertDatabaseCount('ai_messages', 4);
    }

    public function test_unenrolled_class_and_foreign_conversation_are_not_found(): void
    {
        $this->mockLlama();
        $professor = User::factory()->create(['role' => 'professor']);
        $otherClassId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Other'])->json('id');

        $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'Hello',
            'class_id' => $otherClassId,
        ])->assertNotFound();

        $conversationId = $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'Hello',
        ])->json('conversation.id');
        $replyId = AiMessage::where('role', 'assistant')->value('id');

        $intruder = User::factory()->create(['role' => 'student']);
        $this->actingAs($intruder)->getJson("/student/questai/conversations/{$conversationId}")->assertNotFound();
        $this->actingAs($intruder)->postJson('/student/questai/messages', [
            'message' => 'Hi',
            'conversation_id' => $conversationId,
        ])->assertNotFound();
        $this->actingAs($intruder)->postJson("/student/questai/messages/{$replyId}/feedback", ['helpful' => true])
            ->assertNotFound();
        $this->actingAs($intruder)->getJson('/student/questai/conversations')->assertOk()->assertJsonCount(0);
    }

    public function test_ai_failure_returns_502_and_keeps_the_question(): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andThrow(new LlamaApiException('down'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($this->student)->postJson('/student/questai/messages', ['message' => 'Explain moles']);

        $res->assertStatus(502);
        $this->assertNotNull($res->json('conversation.id'));
        $this->assertDatabaseCount('ai_messages', 1);
        $this->assertDatabaseMissing('ai_messages', ['role' => 'assistant']);
    }

    public function test_feedback_is_stored_only_on_assistant_messages(): void
    {
        $this->mockLlama();
        $this->actingAs($this->student)->postJson('/student/questai/messages', ['message' => 'Explain moles'])
            ->assertCreated();

        $reply = AiMessage::where('role', 'assistant')->first();
        $question = AiMessage::where('role', 'user')->first();

        $this->actingAs($this->student)->postJson("/student/questai/messages/{$reply->id}/feedback", ['helpful' => false])
            ->assertOk()->assertJsonPath('feedback', -1);
        $this->assertSame(-1, $reply->fresh()->feedback);

        $this->actingAs($this->student)->postJson("/student/questai/messages/{$question->id}/feedback", ['helpful' => true])
            ->assertStatus(422);
    }

    public function test_conversation_list_and_detail(): void
    {
        $this->mockLlama();
        $conversationId = $this->actingAs($this->student)->postJson('/student/questai/messages', [
            'message' => 'Explain moles',
            'class_id' => $this->classId,
        ])->json('conversation.id');

        $this->actingAs($this->student)->getJson('/student/questai/conversations')
            ->assertOk()
            ->assertJsonPath('0.id', $conversationId)
            ->assertJsonPath('0.class_id', $this->classId);

        $this->actingAs($this->student)->getJson("/student/questai/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'assistant');
    }

    public function test_professor_cannot_use_student_questai(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);

        // EnsureStudentRole redirects non-students to /login.
        $this->actingAs($professor)->getJson('/student/questai/context')->assertRedirect('/login');
        $this->actingAs($professor)->postJson('/student/questai/messages', ['message' => 'Hi'])
            ->assertRedirect('/login');
        $this->assertSame(0, AiConversation::count());
    }
}
