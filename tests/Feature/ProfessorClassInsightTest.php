<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\ClassRoom;
use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\User;
use App\Services\AI\LlamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProfessorClassInsightTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private int $classId;

    private int $weakId;

    private int $strongId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $this->weakId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Stoichiometry'])->json('id');
        $this->strongId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');
    }

    /** Enroll a student and give them a quiz history via the real attempt flow. */
    private function studentWithAnswers(string $name, array $correctness): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => "Lesson for {$name}",
            'attachment_path' => 'class-posts/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);
        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama', 'generated_at' => now()]);

        $answers = [];
        foreach ($correctness as $i => [$competencyId, $correct]) {
            $question = $quiz->questions()->create([
                'competency_id' => $competencyId,
                'question_text' => "Question {$i}",
                'choices' => ['Right', 'Wrong', 'Nope', 'No'],
                'correct_answer' => 'Right',
                'explanation' => 'Because.',
                'difficulty' => 'easy',
                'order_index' => $i,
            ]);
            $answers[] = ['question_id' => $question->id, 'selected_index' => $correct ? 0 : 1];
        }

        $this->actingAs($student)
            ->postJson("/student/classes/{$this->classId}/posts/{$post->id}/quiz/attempts", ['answers' => $answers])
            ->assertCreated();

        return $student;
    }

    private function seedClassActivity(): void
    {
        $this->studentWithAnswers('Alice Reyes', [
            [$this->strongId, true], [$this->strongId, true], [$this->strongId, true], [$this->strongId, true],
            [$this->weakId, false], [$this->weakId, false],
        ]);
    }

    private function aiReply(): array
    {
        return [
            'content' => json_encode(['insights' => [
                ['type' => 'weakness', 'competency_id' => $this->weakId, 'text' => 'Re-teach mole ratios with worked examples.'],
                ['type' => 'strength', 'competency_id' => 9999, 'text' => 'The class handles atomic structure well.'],
                ['type' => 'next_step', 'competency_id' => null, 'text' => 'Dropped: not a class insight type.'],
            ]]),
            'model' => 'llama-3.3-70b-instruct',
        ];
    }

    public function test_no_assessed_data_returns_empty_without_calling_ai(): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->getJson('/professor/analytics/insights')
            ->assertOk()
            ->assertJsonPath('source', 'empty')
            ->assertJsonPath('insights', []);
    }

    public function test_returns_validated_ai_notes_without_sending_student_names(): void
    {
        $this->seedClassActivity();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->withArgs(function (array $messages, array $options) {
            $prompt = implode("\n", array_column($messages, 'content'));

            // Short limits so a slow Llama hands over to DeepSeek quickly.
            return $options['timeout'] === 8
                && $options['max_retries'] === 0
                && $options['fallback_timeout'] === 15
                && str_contains($prompt, 'Stoichiometry')
                && str_contains($prompt, 'Scope: All sections')
                && ! str_contains($prompt, 'Alice');
        })->andReturn($this->aiReply());
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($this->professor)->getJson('/professor/analytics/insights')->assertOk();
        $res->assertJsonPath('source', 'ai');
        $res->assertJsonCount(2, 'insights');
        $res->assertJsonPath('insights.0.type', 'weakness');
        $res->assertJsonPath('insights.0.competency_name', 'Stoichiometry');
        // Unknown competency id from the model is not trusted.
        $res->assertJsonPath('insights.1.competency_name', null);

        // Served from cache — chat() is only expected once.
        $this->actingAs($this->professor)->getJson('/professor/analytics/insights')
            ->assertOk()->assertJsonPath('source', 'ai');
    }

    public function test_falls_back_to_rule_based_notes_when_ai_fails(): void
    {
        $this->seedClassActivity();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andThrow(new LlamaApiException('down'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($this->professor)->getJson('/professor/analytics/insights')->assertOk();
        $res->assertJsonPath('source', 'rules');

        $types = array_column($res->json('insights'), 'type');
        $this->assertContains('weakness', $types);
        $this->assertContains('Stoichiometry', array_column($res->json('insights'), 'competency_name'));
    }

    public function test_falls_back_when_ai_returns_malformed_json(): void
    {
        $this->seedClassActivity();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andReturn(['content' => 'not json at all', 'model' => 'llama']);
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->getJson('/professor/analytics/insights')
            ->assertOk()
            ->assertJsonPath('source', 'rules');
    }

    public function test_other_professors_section_is_not_found(): void
    {
        $stranger = User::factory()->create(['role' => 'professor']);

        $this->actingAs($stranger)->getJson("/professor/analytics/insights?class_id={$this->classId}")->assertNotFound();
    }
}
