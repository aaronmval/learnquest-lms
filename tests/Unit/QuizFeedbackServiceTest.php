<?php

namespace Tests\Unit;

use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Quiz\QuizFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizFeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuiz(): Quiz
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);
        $class = $subject->sections()->create(['professor_id' => $professor->id, 'name' => 'STEM A']);
        $competency = $subject->competencies()->create(['name' => 'Atomic Structure']);

        $post = ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        $quiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);

        $quiz->questions()->create([
            'competency_id' => $competency->id,
            'question_text' => 'Q1',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        return $quiz;
    }

    private function makeAttempt(Quiz $quiz): QuizAttempt
    {
        $student = User::factory()->create(['role' => 'student']);

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }

    public function test_summary_with_no_feedback_returns_zeroed_shape(): void
    {
        $quiz = $this->makeQuiz();
        $service = new QuizFeedbackService;

        $summary = $service->summaryForQuiz($quiz);

        $this->assertSame(0, $summary['count']);
        $this->assertNull($summary['average_rating']);
        foreach (['too_easy', 'just_right', 'too_hard'] as $key) {
            $this->assertSame(0, $summary['difficulty'][$key]['count']);
            $this->assertSame(0, $summary['difficulty'][$key]['percent']);
        }
        $this->assertSame([], $summary['recent_comments']);
    }

    public function test_summary_computes_correct_average_and_percentages(): void
    {
        $quiz = $this->makeQuiz();
        $service = new QuizFeedbackService;

        $ratingsAndDifficulty = [[5, 'too_hard'], [3, 'too_hard'], [4, 'just_right']];
        foreach ($ratingsAndDifficulty as [$rating, $difficulty]) {
            $attempt = $this->makeAttempt($quiz);
            $service->submit($attempt, $attempt->student, ['rating' => $rating, 'difficulty' => $difficulty]);
        }

        $summary = $service->summaryForQuiz($quiz);

        $this->assertSame(3, $summary['count']);
        $this->assertEqualsWithDelta(4.0, $summary['average_rating'], 1e-9); // (5+3+4)/3
        $this->assertSame(0, $summary['difficulty']['too_easy']['percent']);
        $this->assertSame(67, $summary['difficulty']['too_hard']['percent']); // 2/3 rounded
        $this->assertSame(33, $summary['difficulty']['just_right']['percent']); // 1/3 rounded
    }

    public function test_prompt_context_is_null_with_no_feedback(): void
    {
        $quiz = $this->makeQuiz();
        $service = new QuizFeedbackService;

        $this->assertNull($service->promptContext($quiz));
    }

    public function test_prompt_context_includes_difficulty_and_comment(): void
    {
        $quiz = $this->makeQuiz();
        $service = new QuizFeedbackService;

        $attempt = $this->makeAttempt($quiz);
        $service->submit($attempt, $attempt->student, [
            'rating' => 2,
            'difficulty' => 'too_hard',
            'comment' => 'Question 3 was confusing.',
        ]);

        $context = $service->promptContext($quiz);

        $this->assertNotNull($context);
        $this->assertStringContainsString('too hard: 100%', $context);
        $this->assertStringContainsString('Question 3 was confusing.', $context);
    }
}
