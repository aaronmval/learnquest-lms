<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Competency;
use App\Models\Quiz;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetencyMergeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A quiz with two "Intermolecular Forces" questions and one "General
     * Chemistry Basics" question, answered by one student through the real
     * submit endpoint (correct, incorrect, correct).
     */
    private function answeredQuiz(): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'General Chemistry 2'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'GC 2 Emerald',
            'section' => 'STEM - Emerald',
        ])->json('id');

        $imf = Competency::create(['subject_id' => $subjectId, 'name' => 'Intermolecular Forces']);
        $basics = Competency::create(['subject_id' => $subjectId, 'name' => 'General Chemistry Basics']);

        $this->actingAs($student)->postJson('/student/classes/join', ['code' => ClassRoom::find($classId)->code])->assertCreated();

        $postId = ClassPost::create([
            'class_id' => $classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '3rd Quarter',
            'title' => 'GENERAL CHEMISTRY 2 SLM 1',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ])->id;

        $quiz = Quiz::create(['class_post_id' => $postId, 'model' => 'llama-3.3-70b-instruct', 'generated_at' => now()]);

        $questions = collect([$imf, $imf, $basics])->map(fn ($c, $i) => $quiz->questions()->create([
            'competency_id' => $c->id,
            'question_text' => "Question {$i}",
            'choices' => ['A', 'B', 'C', 'D'],
            'correct_answer' => 'A',
            'explanation' => 'Because.',
            'difficulty' => 'easy',
            'order_index' => $i,
        ]));

        $this->actingAs($student)->postJson("/student/classes/{$classId}/posts/{$postId}/quiz/attempts", [
            'answers' => [
                ['question_id' => $questions[0]->id, 'selected_index' => 0],
                ['question_id' => $questions[1]->id, 'selected_index' => 1],
                ['question_id' => $questions[2]->id, 'selected_index' => 0],
            ],
        ])->assertCreated();

        return [$student, $imf, $basics, $questions];
    }

    public function test_moves_questions_and_answers_deletes_competency_and_rebuilds_mastery(): void
    {
        [$student, $imf, $basics, $questions] = $this->answeredQuiz();

        $this->artisan('learnquest:merge-competency', ['name' => 'General Chemistry Basics'])->assertSuccessful();

        $this->assertModelMissing($basics);
        $this->assertSame($imf->id, $questions[2]->fresh()->competency_id);
        $this->assertDatabaseMissing('quiz_answers', ['competency_id' => $basics->id]);
        $this->assertDatabaseCount('student_mastery', 1);

        // Same result BKT gives for the three answers in order.
        $bkt = app(BayesianKnowledgeTracingService::class);
        $expected = $bkt->replay((float) config('bkt.default_pl0'), [
            'p_g' => (float) config('bkt.default_pg'),
            'p_s' => (float) config('bkt.default_ps'),
            'p_t' => (float) config('bkt.default_pt'),
        ], [true, false, true]);

        $mastery = StudentMastery::where('student_id', $student->id)->where('competency_id', $imf->id)->sole();
        $this->assertSame(3, $mastery->observations_count);
        $this->assertSame(2, $mastery->correct_count);
        $this->assertSame(1, $mastery->incorrect_count);
        $this->assertEqualsWithDelta(end($expected), (float) $mastery->current_mastery, 0.0001);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [, , $basics, $questions] = $this->answeredQuiz();

        $this->artisan('learnquest:merge-competency', ['name' => 'General Chemistry Basics', '--dry-run' => true])->assertSuccessful();

        $this->assertModelExists($basics);
        $this->assertSame($basics->id, $questions[2]->fresh()->competency_id);
    }

    public function test_quiz_using_only_that_competency_needs_into(): void
    {
        [, $imf, $basics, $questions] = $this->answeredQuiz();
        $questions[0]->update(['competency_id' => $basics->id]);
        $questions[1]->update(['competency_id' => $basics->id]);

        $this->artisan('learnquest:merge-competency', ['name' => 'General Chemistry Basics'])->assertFailed();
        $this->assertModelExists($basics);

        $this->artisan('learnquest:merge-competency', ['name' => 'General Chemistry Basics', '--into' => 'Intermolecular Forces'])->assertSuccessful();
        $this->assertModelMissing($basics);
        $this->assertSame($imf->id, $questions[0]->fresh()->competency_id);
    }
}
