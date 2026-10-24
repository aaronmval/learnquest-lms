<?php

namespace Database\Seeders;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Competency;
use App\Models\Module;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizFeedback;
use App\Models\QuizQuestion;
use App\Models\QuizSetting;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\AdaptiveQuizService;
use App\Services\Quiz\QuizAttemptService;
use App\Services\Quiz\QuizSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for the professor dashboard: students in three General
 * Chemistry 2 sections who take quizzes on SLM 1–10 between August and
 * October. Data is shaped exactly like real use — modules are posted to
 * every section the way the Modules page does it (PDF copied onto the
 * card), each attempt is opened through the adaptive selector and graded
 * by QuizAttemptService with a frozen clock, so quiz_answers and
 * student_mastery come from the real BKT path.
 *
 * Emerald's section, module uploads and lesson posts are re-dated to a
 * weekly release schedule so the history is consistent. SLM 11–18 get no
 * quiz, so generation can still be demonstrated; generating on SLM 1–10
 * replaces (archives) the seeded quiz as usual.
 *
 * Not part of DatabaseSeeder. Run with `php artisan learnquest:sample-data`
 * and remove with `php artisan learnquest:sample-data --clean`; clean()
 * only touches rows this seeder created (sample-domain students, quizzes
 * built from this seeder's question bank, and the two extra sections).
 */
class SampleAnalyticsSeeder extends Seeder
{
    public const PROFESSOR_EMAIL = 'aaronmarkvaliente@gmail.com';

    public const SUBJECT_NAME = 'General Chemistry 2';

    // Reserved .test TLD: looks institutional but can never be a real inbox.
    public const SAMPLE_DOMAIN = 'kapitolyo-shs.test';

    // Markers used by an earlier version of this seeder, still cleaned up.
    private const LEGACY_DOMAIN = 'sample.learnquest.test';

    private const LEGACY_QUIZ_MODEL = 'sample-seed';

    private const EXISTING_SECTION = 'GC 2 Emerald';

    /** name => [section, student count, ability shift] */
    private const SECTIONS = [
        'GC 2 Emerald' => ['STEM - Emerald', 30, 0.00],
        'GC 2 Ruby' => ['STEM - Ruby', 25, -0.09],
        'GC 2 Sapphire' => ['STEM - Sapphire', 25, 0.05],
    ];

    private const QUIZZED_LESSONS = 10;

    private const TERM_START = '2026-07-27 08:00:00';

    private const MODULES_UPLOADED = '2026-07-30 19:12:00';

    private const FIRST_RELEASE = '2026-08-03 07:30:00';

    // SLM 1–12 are released weekly; the rest of the quarter's modules were
    // posted together in the latest week.
    private const WEEKLY_LESSONS = 12;

    private const FIRST_NAMES = [
        'Juan', 'Maria', 'Jose', 'Andrea', 'Mark', 'Angelica', 'John Paul', 'Kristine', 'Carlo', 'Patricia',
        'Miguel', 'Camille', 'Paolo', 'Bea', 'Rafael', 'Nicole', 'Gabriel', 'Erika', 'Joshua', 'Danica',
        'Christian', 'Jasmine', 'Kevin', 'Princess', 'Bryan', 'Shaira', 'Renz', 'Alyssa', 'Jerome', 'Trisha',
        'Lance', 'Hannah', 'Vincent', 'Clarisse', 'Adrian', 'Mikaela', 'Ivan', 'Janelle', 'Ralph', 'Sofia',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada',
        'Castillo', 'Flores', 'Villanueva', 'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado',
        'Domingo', 'Gonzales', 'Lopez', 'Del Rosario', 'Pascual', 'Soriano', 'Marquez', 'Dela Cruz', 'Gutierrez', 'Valdez',
        'Manalo', 'Fernandez', 'Lim', 'Tan', 'Robles', 'Aguilar', 'Esguerra', 'Panganiban', 'Mallari', 'Sarmiento',
    ];

    private const FEEDBACK_COMMENTS = [
        'The questions matched the lesson well.',
        'Some computations were tricky but fair.',
        'I need to review this lesson again.',
        'Explanations after the quiz really helped.',
        'More practice problems please!',
        'Hard at first but I understood after reading the feedback.',
        null, null, null,
    ];

    public function __construct(
        private QuizAttemptService $attempts,
        private QuizSettingsService $settings,
        private AdaptiveQuizService $adaptiveQuiz,
    ) {}

    public function run(): void
    {
        $professor = User::where('email', self::PROFESSOR_EMAIL)->where('role', 'professor')->first();
        $subject = $professor ? Subject::where('owner_id', $professor->id)->where('name', self::SUBJECT_NAME)->first() : null;

        if (! $professor || ! $subject) {
            throw new RuntimeException('Professor '.self::PROFESSOR_EMAIL.' with subject "'.self::SUBJECT_NAME.'" not found.');
        }

        $emerald = ClassRoom::where('professor_id', $professor->id)
            ->where('subject_id', $subject->id)
            ->where('name', self::EXISTING_SECTION)
            ->whereNull('archived_at')
            ->first();

        if (! $emerald) {
            throw new RuntimeException('Section "'.self::EXISTING_SECTION.'" not found for '.self::PROFESSOR_EMAIL.'.');
        }

        $modules = $this->modulesBySlm($subject);

        if ($modules->isEmpty()) {
            throw new RuntimeException('No "... SLM n" modules found under "'.self::SUBJECT_NAME.'".');
        }

        $this->clean();
        mt_srand(2026);

        $competencies = Competency::where('subject_id', $subject->id)->get()->keyBy('name');
        $password = Hash::make('password');

        try {
            $this->redateModules($modules);
            $sections = $this->sections($professor, $subject, $emerald, $modules);
            $quizzes = $this->quizzes($sections, $competencies);

            $totalStudents = 0;
            $totalAttempts = 0;
            $nameIndex = 0;

            foreach (self::SECTIONS as $name => [, $count, $shift]) {
                $class = $sections[$name]['class'];

                for ($i = 0; $i < $count; $i++, $nameIndex++) {
                    $student = $this->createStudent($nameIndex, $password, $class);
                    $totalAttempts += $this->simulateStudent($student, $quizzes[$name], $shift);
                    $totalStudents++;
                }
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info("Seeded {$totalStudents} sample students and {$totalAttempts} quiz attempts across ".count(self::SECTIONS).' sections.');
        $this->command?->info('Sample student logins: <first.last>@'.self::SAMPLE_DOMAIN.' / password');
    }

    /**
     * Remove everything this seeder created; real accounts, posts and quizzes
     * are left alone. Emerald's re-dated timestamps are not restored.
     */
    public function clean(): void
    {
        DB::transaction(function () {
            $users = User::where('email', 'like', '%@'.self::SAMPLE_DOMAIN)
                ->orWhere('email', 'like', '%@'.self::LEGACY_DOMAIN)
                ->delete();

            // Seeded quizzes (current or archived after a regeneration) are
            // the ones made only of this seeder's bank questions.
            $bankTexts = collect($this->questionBank())->flatten(1)->pluck(2)->all();
            $bankQuizIds = QuizQuestion::whereIn('question_text', $bankTexts)->distinct()->pluck('quiz_id');
            $mixedQuizIds = QuizQuestion::whereIn('quiz_id', $bankQuizIds)->whereNotIn('question_text', $bankTexts)->distinct()->pluck('quiz_id');
            $legacy = Quiz::where('model', self::LEGACY_QUIZ_MODEL)->get(['id', 'class_post_id']);

            $quizzes = Quiz::whereIn('id', $bankQuizIds->diff($mixedQuizIds)->merge($legacy->pluck('id')))->delete();

            // The earlier version also created quiz settings for its quizzes.
            $legacyPosts = $legacy->pluck('class_post_id')->unique();
            QuizSetting::whereIn('class_post_id', $legacyPosts)
                ->whereNotIn('class_post_id', Quiz::whereIn('class_post_id', $legacyPosts)->select('class_post_id'))
                ->delete();

            $professor = User::where('email', self::PROFESSOR_EMAIL)->first();
            $classes = $professor
                ? ClassRoom::where('professor_id', $professor->id)
                    ->whereIn('name', array_diff(array_keys(self::SECTIONS), [self::EXISTING_SECTION]))
                    ->get()
                : collect();

            // Each section's post attachments live under class-posts/{id}.
            $classIds = $classes->pluck('id');
            ClassRoom::whereIn('id', $classIds)->delete();

            DB::afterCommit(fn () => $classIds->each(fn ($id) => Storage::disk()->deleteDirectory("class-posts/{$id}")));

            if ($users || $quizzes || $classes->isNotEmpty()) {
                $this->command?->info("Removed {$users} sample students, {$quizzes} sample quizzes and {$classes->count()} sample sections.");
            }
        });
    }

    /**
     * The subject's modules keyed by SLM number, parsed from "... SLM n".
     *
     * @return Collection<int, Module>
     */
    private function modulesBySlm(Subject $subject): Collection
    {
        return Module::where('subject_id', $subject->id)
            ->get()
            ->filter(fn (Module $m) => preg_match('/SLM\s*(\d+)$/i', $m->title))
            ->keyBy(fn (Module $m) => (int) preg_replace('/.*SLM\s*(\d+)$/i', '$1', $m->title))
            ->sortKeys();
    }

    /** The quarter's modules were uploaded as one batch before the term started. */
    private function redateModules(Collection $modules): void
    {
        foreach ($modules->values() as $i => $module) {
            $at = Carbon::parse(self::MODULES_UPLOADED)->addSeconds($i * 3);
            DB::table('modules')->where('id', $module->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    /**
     * Emerald plus the two sample sections. Every module is targeted to
     * every section and posted on its release date, the way the Modules
     * page posts it (lesson post carrying a copy of the PDF). Emerald's
     * existing posts are kept and only re-dated.
     *
     * @return array<string, array{class: ClassRoom, posts: Collection<int, ClassPost>}>
     */
    private function sections(User $professor, Subject $subject, ClassRoom $emerald, Collection $modules): array
    {
        DB::table('classes')->where('id', $emerald->id)->update(['created_at' => Carbon::parse(self::TERM_START)]);

        $sections = [];

        foreach (self::SECTIONS as $name => [$section]) {
            if ($name === self::EXISTING_SECTION) {
                $class = $emerald;
            } else {
                Carbon::setTestNow(Carbon::parse(self::TERM_START)->addMinutes(mt_rand(5, 90)));
                $class = ClassRoom::create([
                    'professor_id' => $professor->id,
                    'name' => $name,
                    'section' => $section,
                    'subject' => $subject->name,
                ]);
                $class->subject_id = $subject->id;
                $class->save();
            }

            $existing = ClassPost::where('class_id', $class->id)->whereNotNull('module_id')->get()->keyBy('module_id');

            $posts = $modules->map(function (Module $module, int $slm) use ($class, $professor, $existing) {
                $module->targetSections()->syncWithoutDetaching([$class->id]);
                $postedAt = $this->releaseDate($slm)->addMinutes(mt_rand(0, 40));

                if ($post = $existing->get($module->id)) {
                    DB::table('class_posts')->where('id', $post->id)->update(['created_at' => $postedAt, 'updated_at' => $postedAt]);

                    return $post;
                }

                Carbon::setTestNow($postedAt);

                return ClassPost::create([
                    'class_id' => $class->id,
                    'author_id' => $professor->id,
                    'module_id' => $module->id,
                    'type' => 'lesson',
                    'quarter' => $module->quarter ?: '1st Quarter',
                    'title' => $module->title,
                    'body' => $module->description,
                ] + $this->copiedAttachment($module, $class));
            });

            $sections[$name] = ['class' => $class, 'posts' => $posts];
        }

        Carbon::setTestNow();

        return $sections;
    }

    /** Same copy ModuleController makes when it posts a module to a class. */
    private function copiedAttachment(Module $module, ClassRoom $class): array
    {
        if (! $module->file_path || ! Storage::disk()->exists($module->file_path)) {
            return ['attachment_path' => null, 'attachment_name' => null];
        }

        $extension = pathinfo($module->file_path, PATHINFO_EXTENSION) ?: 'pdf';
        $path = "class-posts/{$class->id}/".Str::random(40).".{$extension}";
        Storage::disk()->copy($module->file_path, $path);

        return ['attachment_path' => $path, 'attachment_name' => $module->file_name];
    }

    /**
     * One quiz per (section, SLM 1–10). Emerald SLM 1 reuses the real quiz
     * already generated for it (re-dated to its lesson's release); other
     * sections get a copy of its questions. No quiz settings are saved, so
     * seeded lessons use the app defaults like any unconfigured lesson.
     *
     * @return array<string, array<int, Quiz>>  section name => [slm => quiz]
     */
    private function quizzes(array $sections, Collection $competencies): array
    {
        $emeraldSlm1 = $sections[self::EXISTING_SECTION]['posts']->get(1);
        $realSlm1 = $emeraldSlm1
            ? Quiz::where('class_post_id', $emeraldSlm1->id)->whereNull('archived_at')->latest('id')->with('activeQuestions')->first()
            : null;

        if ($realSlm1) {
            $generatedAt = $this->releaseDate(1)->addHours(2);
            DB::table('quizzes')->where('id', $realSlm1->id)->update(['generated_at' => $generatedAt, 'created_at' => $generatedAt]);
        }

        $model = config('services.routeway.model', 'llama-3.3-70b-instruct');
        $bank = $this->questionBank();
        $quizzes = [];

        foreach ($sections as $name => ['posts' => $posts]) {
            for ($slm = 1; $slm <= self::QUIZZED_LESSONS; $slm++) {
                $post = $posts->get($slm);
                if (! $post) {
                    continue;
                }

                if ($slm === 1 && $name === self::EXISTING_SECTION && $realSlm1) {
                    $quizzes[$name][$slm] = $realSlm1->setRelation('classPost', $post);

                    continue;
                }

                $questions = $slm === 1
                    ? $this->copiedQuestions($realSlm1)
                    : $this->bankQuestions($bank[$slm] ?? [], $competencies);

                if (! $questions) {
                    continue;
                }

                Carbon::setTestNow($this->releaseDate($slm)->addHours(mt_rand(1, 6)));

                $quiz = Quiz::create([
                    'class_post_id' => $post->id,
                    'model' => $model,
                    'generated_at' => now(),
                ]);
                foreach ($questions as $i => $q) {
                    $quiz->questions()->create($q + ['order_index' => $i]);
                }

                $quizzes[$name][$slm] = $quiz->setRelation('classPost', $post);
            }
        }

        Carbon::setTestNow();

        return $quizzes;
    }

    private function copiedQuestions(?Quiz $source): array
    {
        return $source
            ? $source->activeQuestions->map(fn ($q) => [
                'competency_id' => $q->competency_id,
                'question_text' => $q->question_text,
                'choices' => $q->choices,
                'correct_answer' => $q->correct_answer,
                'explanation' => $q->explanation,
                'difficulty' => $q->difficulty,
            ])->all()
            : [];
    }

    private function bankQuestions(array $entries, Collection $competencies): array
    {
        $questions = [];

        foreach ($entries as [$competency, $difficulty, $text, $choices, $correct, $explanation]) {
            $competencyId = $competencies->get($competency)?->id;
            if (! $competencyId) {
                $this->command?->warn("Competency \"{$competency}\" not found — skipping a question.");

                continue;
            }

            // Bank entries list the answer first; shuffle so it isn't always "A".
            $answer = $choices[$correct];
            for ($i = count($choices) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$choices[$i], $choices[$j]] = [$choices[$j], $choices[$i]];
            }

            $questions[] = [
                'competency_id' => $competencyId,
                'question_text' => $text,
                'choices' => $choices,
                'correct_answer' => $answer,
                'explanation' => $explanation,
                'difficulty' => $difficulty,
            ];
        }

        return $questions;
    }

    private function createStudent(int $index, string $password, ClassRoom $class): User
    {
        $first = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[($index * 7 + intdiv($index, count(self::FIRST_NAMES))) % count(self::LAST_NAMES)];
        $slug = fn (string $s) => strtolower(preg_replace('/[^a-z]/i', '', $s));

        // Students join their section during the week before the first lesson.
        Carbon::setTestNow(Carbon::parse(self::TERM_START)->addDays(mt_rand(1, 6))->setTime(mt_rand(7, 21), mt_rand(0, 59)));

        $student = User::create([
            'name' => "{$first} {$last}",
            'email' => $slug($first).'.'.$slug($last).'@'.self::SAMPLE_DOMAIN,
            'password' => $password,
            'role' => 'student',
        ]);
        $student->forceFill(['email_verified_at' => now()])->save();
        $class->students()->attach($student->id);

        Carbon::setTestNow();

        return $student;
    }

    /**
     * Answer each quiz from a latent ability profile: a base level, a
     * per-competency offset, slow improvement across lessons, and a
     * difficulty adjustment per question. Returns attempts created.
     *
     * @param  array<int, Quiz>  $quizzes
     */
    private function simulateStudent(User $student, array $quizzes, float $sectionShift): int
    {
        $roll = $this->rand();
        [$mean, $skipRate] = match (true) {
            $roll < 0.25 => [0.84, 0.03],   // strong
            $roll < 0.75 => [0.63, 0.08],   // average
            default => [0.34, 0.18],        // struggling
        };
        $ability = $mean + $sectionShift + $this->gaussian(0, 0.06);
        $offsets = [];
        $count = 0;

        foreach ($quizzes as $slm => $quiz) {
            if ($this->rand() < $skipRate) {
                continue;
            }

            $when = $this->releaseDate($slm)->addDays(mt_rand(1, 5))->setTime(mt_rand(8, 21), mt_rand(0, 59));
            $growth = 0.012 * ($slm - 1);
            $result = $this->takeQuiz($student, $quiz, $when, $ability + $growth, $offsets);
            $count++;

            // Low scorers sometimes retake after reviewing the feedback.
            if ($result['percent'] < 55 && $this->rand() < 0.45) {
                $this->takeQuiz($student, $quiz, $when->copy()->addDays(mt_rand(1, 3))->addHours(mt_rand(0, 6)), $ability + $growth + 0.12, $offsets);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Open the attempt the way QuizGenerationController::openAttempt does
     * (adaptive question selection from the student's BKT mastery at that
     * moment), answer the served questions, then submit a few minutes later.
     */
    private function takeQuiz(User $student, Quiz $quiz, Carbon $when, float $ability, array &$offsets): array
    {
        Carbon::setTestNow($when);

        $settings = $this->settings->for($quiz->classPost);
        $active = $quiz->activeQuestions()->with('competency')->get();
        $mastery = $settings['adaptive'] ? $this->adaptiveQuiz->masteryFor($student, $active) : null;
        $mix = $this->adaptiveQuiz->mixFor($settings['difficulty_mix'], $mastery['level'] ?? null);
        $served = $this->adaptiveQuiz->select($active, $settings['question_count'], $mix);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'deadline_at' => $settings['time_limit_minutes'] !== null ? now()->addMinutes($settings['time_limit_minutes']) : null,
            'question_ids' => $served->pluck('id')->all(),
            'mastery_level' => $mastery['level'] ?? null,
            'status' => 'in_progress',
        ]);

        $answers = $served->map(function ($q) use ($ability, &$offsets) {
            $offsets[$q->competency_id] ??= $this->gaussian(0, 0.09);

            if ($this->rand() < 0.02) {
                return ['question_id' => $q->id, 'selected_index' => null];
            }

            $p = $ability + $offsets[$q->competency_id] + match ($q->difficulty) {
                'easy' => 0.12,
                'hard' => -0.15,
                default => 0.0,
            };
            $correctIndex = array_search($q->correct_answer, $q->choices, true);

            if ($this->rand() < max(0.08, min(0.97, $p))) {
                $selected = $correctIndex;
            } else {
                $wrong = array_values(array_diff(array_keys($q->choices), [$correctIndex]));
                $selected = $wrong[mt_rand(0, count($wrong) - 1)];
            }

            return ['question_id' => $q->id, 'selected_index' => $selected];
        })->all();

        $submittedAt = $when->copy()->addMinutes(mt_rand(4, 18))->addSeconds(mt_rand(0, 59));
        Carbon::setTestNow($submittedAt);
        $result = $this->attempts->submit($quiz, $student, $answers, $attempt);

        if ($this->rand() < 0.5) {
            Carbon::setTestNow($submittedAt->copy()->addMinutes(mt_rand(1, 5)));
            $percent = $result['score']['percent'];

            QuizFeedback::create([
                'quiz_attempt_id' => $result['attempt_id'],
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'rating' => $percent >= 70 ? mt_rand(4, 5) : mt_rand(3, 5),
                'difficulty' => match (true) {
                    $percent >= 90 && $this->rand() < 0.5 => 'too_easy',
                    $percent < 50 && $this->rand() < 0.6 => 'too_hard',
                    default => 'just_right',
                },
                'comment' => self::FEEDBACK_COMMENTS[mt_rand(0, count(self::FEEDBACK_COMMENTS) - 1)],
            ]);
        }

        Carbon::setTestNow();

        return $result['score'];
    }

    /**
     * Weekly Monday releases from Aug 3 for SLM 1–12; the remaining modules
     * were posted together over the latest week (all before today).
     */
    private function releaseDate(int $slm): Carbon
    {
        if ($slm <= self::WEEKLY_LESSONS) {
            return Carbon::parse(self::FIRST_RELEASE)->addWeeks($slm - 1);
        }

        return Carbon::parse(self::FIRST_RELEASE)->addWeeks(self::WEEKLY_LESSONS - 1)->addHours(16 * ($slm - self::WEEKLY_LESSONS));
    }

    private function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function gaussian(float $mean, float $sd): float
    {
        $u = max($this->rand(), 1e-9);
        $v = $this->rand();

        return $mean + $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    /**
     * Hand-written General Chemistry 2 questions for SLM 2–10, keyed by SLM.
     * Each entry: [competency name, difficulty, question, choices, correct index, explanation].
     */
    private function questionBank(): array
    {
        $liquids = 'Properties of Liquids and Solids';
        $water = 'Properties of Water';
        $solids = 'Crystalline and Amorphous Solids';
        $phase = 'Phase Diagrams';
        $curves = 'Heating and Cooling Curves';
        $conc = 'Concentration of Solutions';
        $stoich = 'Stoichiometric Calculations in Solution';
        $lab = 'Laboratory Concentration Determination';
        $colligative = 'Colligative Properties of Solutions';
        $effect = 'Effect of Concentration on Colligative Properties';
        $bpfp = 'Boiling Point Elevation and Freezing Point Depression';
        $molar = 'Molar Mass via Colligative Properties';
        $firstLaw = 'First Law of Thermodynamics';
        $enthalpy = 'Enthalpy of Reaction';
        $hess = "Hess's Law";

        return [
            2 => [
                [$liquids, 'easy', 'Which property of a liquid describes its resistance to flow?', ['Viscosity', 'Surface tension', 'Vapor pressure', 'Capillary action'], 0, 'Viscosity is the resistance of a liquid to flow; stronger intermolecular forces generally mean higher viscosity.'],
                [$liquids, 'easy', 'Surface tension in a liquid is caused by:', ['Unbalanced inward attractions on molecules at the surface', 'The weight of the liquid pushing downward', 'Air pressure acting on the surface', 'Chemical bonds breaking at the surface'], 0, 'Surface molecules are pulled inward by neighbors below and beside them, so the surface behaves like a stretched film.'],
                [$liquids, 'medium', 'As the temperature of most liquids increases, their viscosity:', ['Decreases', 'Increases', 'Stays the same', 'First increases then decreases'], 0, 'Higher kinetic energy helps molecules overcome intermolecular attractions, so the liquid flows more easily.'],
                [$liquids, 'medium', 'Which liquid is expected to have the highest vapor pressure at room temperature?', ['Diethyl ether', 'Water', 'Glycerol', 'Ethylene glycol'], 0, 'Diethyl ether has the weakest intermolecular forces of the choices (no hydrogen bonding between its molecules), so it evaporates most readily.'],
                [$liquids, 'hard', 'Water rises in a narrow glass tube because:', ['Adhesive forces between water and glass exceed cohesive forces in water', 'Cohesive forces in water exceed adhesive forces to glass', 'Glass repels water molecules', 'The tube lowers the density of water'], 0, 'Capillary action occurs when attraction to the tube wall (adhesion) is stronger than attraction among the liquid molecules (cohesion).'],
                [$water, 'easy', 'Why does ice float on liquid water?', ['Ice is less dense because hydrogen bonds hold molecules in an open lattice', 'Ice contains trapped air bubbles only', 'Ice molecules are lighter than liquid water molecules', 'Ice is nonpolar while liquid water is polar'], 0, 'Hydrogen bonding locks ice into an open hexagonal structure that takes up more space than the liquid, lowering its density.'],
                [$water, 'medium', "Water's unusually high specific heat is mainly due to:", ['Extensive hydrogen bonding between molecules', 'Its low molar mass', 'Its nonpolar O–H bonds', 'Its weak London dispersion forces'], 0, 'Energy must first disrupt hydrogen bonds before molecules move faster, so water absorbs a lot of heat per degree of temperature change.'],
                [$water, 'hard', 'Which property of water allows lakes to freeze from the top down, protecting aquatic life?', ['Water is densest at about 4 °C', 'Water has a low surface tension', 'Water is a nonpolar molecule', 'Water has a low boiling point'], 0, 'Water at about 4 °C sinks to the bottom while colder, less dense water and ice stay on top and insulate the water below.'],
            ],
            3 => [
                [$solids, 'easy', 'Which of the following is an amorphous solid?', ['Glass', 'Table salt', 'Quartz', 'Diamond'], 0, 'Glass lacks a long-range repeating arrangement of particles, which makes it amorphous.'],
                [$solids, 'easy', 'Crystalline solids are characterized by:', ['An orderly, repeating arrangement of particles', 'A random arrangement of particles', 'Particles that move freely past one another', 'No definite shape'], 0, 'Crystalline solids have particles arranged in a regular, repeating three-dimensional pattern (a crystal lattice).'],
                [$solids, 'medium', 'When an amorphous solid is heated, it:', ['Softens gradually over a range of temperatures', 'Melts sharply at one definite temperature', 'Sublimes immediately', 'Does not change at all'], 0, 'Without a uniform lattice, the forces between particles vary, so amorphous solids soften over a temperature range.'],
                [$solids, 'medium', 'Sodium chloride (NaCl) is classified as which type of crystalline solid?', ['Ionic', 'Molecular', 'Metallic', 'Covalent network'], 0, 'NaCl is made of Na⁺ and Cl⁻ ions held together by electrostatic attraction — an ionic solid.'],
                [$solids, 'medium', 'Diamond is an example of a:', ['Covalent network solid', 'Molecular solid', 'Ionic solid', 'Metallic solid'], 0, 'Every carbon atom in diamond is covalently bonded to four others in a continuous network.'],
                [$solids, 'hard', 'Which solid conducts electricity in the solid state?', ['Copper', 'Sodium chloride', 'Sucrose', 'Ice'], 0, 'Metallic solids such as copper have delocalized electrons that move freely; ionic solids conduct only when molten or dissolved.'],
                [$solids, 'hard', 'Ice and dry ice (solid CO₂) are both examples of:', ['Molecular solids', 'Ionic solids', 'Covalent network solids', 'Metallic solids'], 0, 'Both consist of discrete molecules held together by intermolecular forces, giving them relatively low melting points.'],
                [$solids, 'easy', 'When a crystalline solid is struck or cut, it tends to:', ['Break along flat planes', 'Shatter into curved, irregular pieces', 'Stretch like rubber', 'Turn into a liquid'], 0, 'The regular lattice gives crystalline solids characteristic cleavage planes.'],
            ],
            4 => [
                [$phase, 'easy', 'The triple point on a phase diagram represents the condition where:', ['Solid, liquid and gas coexist in equilibrium', 'Only the gas phase exists', 'The liquid boils at 1 atm', 'The solid melts at 1 atm'], 0, 'At the triple point, temperature and pressure allow all three phases to exist together.'],
                [$phase, 'medium', 'Beyond the critical point of a substance:', ['Liquid and gas phases become indistinguishable', 'Only the solid phase can exist', 'The substance cannot change phase', 'The substance always decomposes'], 0, 'Above the critical temperature and pressure, the substance becomes a supercritical fluid.'],
                [$phase, 'medium', 'The line separating the solid and liquid regions of a phase diagram shows:', ['Conditions where melting and freezing are at equilibrium', 'Conditions where the substance boils', 'Conditions where the substance sublimes', 'The critical temperature only'], 0, 'Points on the solid–liquid line are melting/freezing points at different pressures.'],
                [$phase, 'hard', "Water's solid–liquid line has a negative slope. This means that increasing pressure on ice just below 0 °C can:", ['Cause the ice to melt', 'Cause the ice to sublime', 'Raise its melting point', 'Turn it directly into gas'], 0, 'Because liquid water is denser than ice, higher pressure favors the liquid phase and lowers the melting point.'],
                [$curves, 'easy', 'On a heating curve, the flat (horizontal) segments represent:', ['Phase changes at constant temperature', 'Heating of a single phase', 'Cooling of the gas', 'Errors in measurement'], 0, 'During a phase change, the added heat breaks intermolecular attractions rather than raising temperature.'],
                [$curves, 'easy', 'The sloped segments of a heating curve show:', ['Temperature rising within a single phase', 'Two phases coexisting', 'The triple point', 'A chemical reaction occurring'], 0, 'When only one phase is present, the added heat increases kinetic energy and temperature.'],
                [$curves, 'medium', 'While water is boiling, the heat added is used mainly to:', ['Overcome intermolecular forces between molecules', 'Raise the temperature of the liquid', 'Break O–H covalent bonds', 'Increase the density of water'], 0, 'The energy goes into separating molecules into the gas phase, so temperature stays at the boiling point.'],
                [$curves, 'hard', 'The boiling plateau on the heating curve of water is longer than the melting plateau because:', ['The enthalpy of vaporization is much larger than the enthalpy of fusion', 'Water boils at a higher temperature', 'Ice has a higher specific heat than steam', 'Melting releases energy'], 0, 'Vaporization must separate molecules almost completely, requiring far more energy (about 40.7 vs 6.01 kJ/mol).'],
            ],
            5 => [
                [$conc, 'easy', 'Molarity (M) is defined as:', ['Moles of solute per liter of solution', 'Moles of solute per kilogram of solvent', 'Grams of solute per 100 g solution', 'Moles of solute per mole of solution'], 0, 'M = mol solute / L solution.'],
                [$conc, 'easy', 'Molality (m) is defined as:', ['Moles of solute per kilogram of solvent', 'Moles of solute per liter of solution', 'Grams of solute per liter of solvent', 'Liters of solute per liter of solution'], 0, 'm = mol solute / kg solvent.'],
                [$conc, 'medium', 'What is the molarity of a solution containing 2.0 mol NaCl in 500 mL of solution?', ['4.0 M', '1.0 M', '0.25 M', '2.0 M'], 0, '2.0 mol ÷ 0.500 L = 4.0 M.'],
                [$conc, 'medium', 'What is the mass percent of sugar when 20 g of sugar is dissolved in 80 g of water?', ['20%', '25%', '80%', '16%'], 0, 'Mass % = 20 g ÷ (20 g + 80 g) × 100 = 20%.'],
                [$conc, 'medium', 'A solution contains 1 mol of solute and 4 mol of solvent. What is the mole fraction of the solute?', ['0.20', '0.25', '0.80', '4.0'], 0, 'X = 1 ÷ (1 + 4) = 0.20.'],
                [$conc, 'hard', 'What is the molality of a solution made by dissolving 0.50 mol glucose in 250 g of water?', ['2.0 m', '0.125 m', '0.50 m', '1.0 m'], 0, '0.50 mol ÷ 0.250 kg = 2.0 m.'],
                [$conc, 'hard', 'Which concentration unit does NOT change with temperature?', ['Molality', 'Molarity', 'Mass/volume percent', 'Volume percent'], 0, 'Molality uses masses only; volumes expand or contract with temperature.'],
                [$conc, 'easy', 'Parts per million (ppm) is most useful for expressing the concentration of:', ['Very dilute solutions', 'Concentrated acids', 'Pure solvents', 'Saturated solutions only'], 0, 'ppm conveniently expresses trace amounts, such as contaminants in drinking water.'],
            ],
            6 => [
                [$stoich, 'medium', 'How many moles of NaOH are in 250 mL of 0.40 M NaOH?', ['0.10 mol', '1.6 mol', '0.40 mol', '0.025 mol'], 0, 'mol = M × V = 0.40 mol/L × 0.250 L = 0.10 mol.'],
                [$stoich, 'medium', '50.0 mL of 6.0 M HCl is diluted to 300.0 mL. What is the new concentration?', ['1.0 M', '3.6 M', '0.50 M', '2.0 M'], 0, 'M₁V₁ = M₂V₂ → (6.0)(50.0) = M₂(300.0) → M₂ = 1.0 M.'],
                [$stoich, 'hard', 'What volume of 0.10 M HCl is needed to neutralize 20.0 mL of 0.20 M NaOH?', ['40.0 mL', '20.0 mL', '10.0 mL', '80.0 mL'], 0, 'mol NaOH = 0.0040 mol; HCl reacts 1:1, so V = 0.0040 ÷ 0.10 = 0.040 L = 40.0 mL.'],
                [$stoich, 'hard', 'Excess NaCl is added to a solution containing 0.020 mol AgNO₃. What mass of AgCl (143.3 g/mol) precipitates?', ['2.87 g', '1.43 g', '0.287 g', '5.73 g'], 0, 'Ag⁺ + Cl⁻ → AgCl is 1:1, so 0.020 mol × 143.3 g/mol = 2.87 g.'],
                [$lab, 'easy', 'In a titration, the point at which the indicator changes color is called the:', ['End point', 'Triple point', 'Critical point', 'Boiling point'], 0, 'The end point is the visible signal that approximates the equivalence point.'],
                [$lab, 'easy', 'Which glassware is used to deliver the titrant precisely during a titration?', ['Buret', 'Beaker', 'Graduated cylinder', 'Test tube'], 0, 'A buret delivers measured, adjustable volumes of titrant.'],
                [$lab, 'medium', 'A standard solution is a solution:', ['Whose concentration is accurately known', 'That is always 1.0 M', 'That contains only water', 'Whose concentration is unknown'], 0, 'Standard solutions of known concentration are used to determine unknown concentrations.'],
                [$lab, 'hard', '25.0 mL of HCl of unknown concentration requires 30.0 mL of 0.100 M NaOH to reach the end point. What is the HCl concentration?', ['0.120 M', '0.0833 M', '0.100 M', '0.300 M'], 0, 'mol NaOH = 0.00300 mol = mol HCl; 0.00300 ÷ 0.0250 L = 0.120 M.'],
            ],
            7 => [
                [$colligative, 'easy', 'Colligative properties depend on:', ['The number of solute particles, not their identity', 'The identity of the solute only', 'The color of the solute', 'The molar mass of the solvent only'], 0, 'Colligative properties depend on how many solute particles are dissolved.'],
                [$colligative, 'easy', 'Which of the following is NOT a colligative property?', ['Color of the solution', 'Vapor-pressure lowering', 'Osmotic pressure', 'Freezing-point depression'], 0, 'Color depends on the identity of the solute, not the number of particles.'],
                [$colligative, 'medium', 'Adding a nonvolatile solute to a solvent makes the vapor pressure of the solvent:', ['Lower', 'Higher', 'Unchanged', 'Equal to atmospheric pressure'], 0, 'Solute particles reduce the fraction of solvent molecules at the surface that can escape (Raoult\'s law).'],
                [$colligative, 'hard', 'Osmosis is the movement of:', ['Solvent through a semipermeable membrane toward the more concentrated solution', 'Solute through a membrane toward the dilute solution', 'Solvent away from the more concentrated solution', 'Ions only, through any membrane'], 0, 'Solvent flows toward higher solute concentration to equalize concentrations across the membrane.'],
                [$effect, 'easy', 'Why is salt spread on icy roads?', ['It lowers the freezing point of water', 'It raises the freezing point of water', 'It reacts with ice to produce heat only', 'It increases the density of ice'], 0, 'Dissolved salt ions depress the freezing point so ice melts at lower temperatures.'],
                [$effect, 'medium', 'Which 0.10 m aqueous solution has the lowest freezing point?', ['CaCl₂', 'NaCl', 'Glucose', 'Sucrose'], 0, 'CaCl₂ produces 3 ions per formula unit (i = 3), giving the most particles and the largest depression.'],
                [$effect, 'medium', 'What is the ideal van \'t Hoff factor (i) for NaCl in water?', ['2', '1', '3', '0.5'], 0, 'NaCl dissociates into Na⁺ and Cl⁻ — two particles per formula unit.'],
                [$effect, 'hard', 'If the molality of a nonelectrolyte solution is doubled, its freezing-point depression:', ['Doubles', 'Is halved', 'Stays the same', 'Quadruples'], 0, 'ΔTf = i·Kf·m is directly proportional to molality.'],
            ],
            8 => [
                [$bpfp, 'easy', 'Adding a nonvolatile solute to water ______ its boiling point.', ['Raises', 'Lowers', 'Does not change', 'Eliminates'], 0, 'Lower vapor pressure means a higher temperature is needed to reach atmospheric pressure.'],
                [$bpfp, 'easy', 'Which equation gives the boiling-point elevation of a solution?', ['ΔTb = i·Kb·m', 'ΔTb = Kb ÷ m', 'ΔTb = M·V', 'ΔTb = q ÷ m'], 0, 'Boiling-point elevation equals the van \'t Hoff factor × ebullioscopic constant × molality.'],
                [$bpfp, 'medium', 'Kb for water is 0.512 °C/m. What is the boiling point of a 1.00 m aqueous glucose solution?', ['100.51 °C', '101.02 °C', '99.49 °C', '100.00 °C'], 0, 'ΔTb = (1)(0.512)(1.00) = 0.512 °C, so Tb ≈ 100.51 °C.'],
                [$bpfp, 'medium', 'Kf for water is 1.86 °C/m. What is the freezing point of a 0.50 m aqueous sucrose solution?', ['−0.93 °C', '−1.86 °C', '0.93 °C', '−0.47 °C'], 0, 'ΔTf = (1)(1.86)(0.50) = 0.93 °C, so Tf = −0.93 °C.'],
                [$bpfp, 'hard', 'What is the freezing point of a 0.20 m NaCl solution (assume i = 2, Kf = 1.86 °C/m)?', ['−0.74 °C', '−0.37 °C', '−1.49 °C', '−0.19 °C'], 0, 'ΔTf = (2)(1.86)(0.20) = 0.744 °C, so Tf ≈ −0.74 °C.'],
                [$molar, 'medium', 'To determine molar mass from a freezing-point depression experiment, you first calculate the:', ['Molality of the solution', 'Density of the solvent', 'Volume of the solution', 'Boiling point of pure solvent'], 0, 'm = ΔTf ÷ (i·Kf); molality then gives moles of solute, and molar mass = grams ÷ moles.'],
                [$molar, 'hard', '10.0 g of a nonelectrolyte dissolved in 100.0 g of water lowers the freezing point by 1.86 °C (Kf = 1.86 °C/m). What is its molar mass?', ['100 g/mol', '10 g/mol', '186 g/mol', '1000 g/mol'], 0, 'm = 1.00 mol/kg → 0.100 mol in 0.100 kg; 10.0 g ÷ 0.100 mol = 100 g/mol.'],
                [$molar, 'hard', 'Which colligative property is best for determining the molar mass of large molecules such as proteins?', ['Osmotic pressure', 'Boiling-point elevation', 'Vapor-pressure lowering', 'Freezing-point depression'], 0, 'Osmotic pressure gives a measurable value even at the very low molar concentrations typical of macromolecules.'],
            ],
            9 => [
                [$firstLaw, 'easy', 'The First Law of Thermodynamics states that:', ['Energy cannot be created or destroyed, only transformed', 'Entropy of the universe always decreases', 'Heat always flows from cold to hot', 'All reactions are exothermic'], 0, 'It is the law of conservation of energy: ΔU = q + w.'],
                [$firstLaw, 'medium', 'A system absorbs 50 J of heat and does 20 J of work on the surroundings. What is ΔU?', ['+30 J', '+70 J', '−30 J', '−70 J'], 0, 'q = +50 J, w = −20 J, so ΔU = +50 − 20 = +30 J.'],
                [$firstLaw, 'medium', 'For an exothermic process, the sign of q (heat for the system) is:', ['Negative', 'Positive', 'Zero', 'It depends on the work done'], 0, 'The system releases heat to the surroundings, so q < 0.'],
                [$firstLaw, 'hard', 'When a gas is compressed by the surroundings, the work (w) done on the system is:', ['Positive', 'Negative', 'Zero', 'Always equal to q'], 0, 'Work done on the system increases its internal energy, so w > 0.'],
                [$enthalpy, 'easy', 'A reaction with ΔH < 0 is:', ['Exothermic', 'Endothermic', 'At equilibrium', 'Non-spontaneous'], 0, 'Negative ΔH means heat is released to the surroundings.'],
                [$enthalpy, 'medium', 'The combustion of methane has ΔH = −890 kJ/mol. How much heat is released when 2.0 mol of methane burns?', ['1780 kJ', '890 kJ', '445 kJ', '3560 kJ'], 0, '2.0 mol × 890 kJ/mol = 1780 kJ released.'],
                [$enthalpy, 'medium', 'Enthalpy change (ΔH) equals the heat exchanged at constant:', ['Pressure', 'Volume', 'Temperature', 'Mass'], 0, 'By definition, ΔH = qₚ (heat at constant pressure).'],
                [$enthalpy, 'hard', 'If ΔH for A → B is +100 kJ, then ΔH for B → A is:', ['−100 kJ', '+100 kJ', '0 kJ', '+200 kJ'], 0, 'Reversing a reaction reverses the sign of ΔH.'],
            ],
            10 => [
                [$hess, 'easy', "Hess's Law states that the total enthalpy change of a reaction:", ['Is the same regardless of the path or number of steps', 'Depends on the number of steps', 'Is always zero', 'Depends on the reaction rate'], 0, 'Because enthalpy is a state function, ΔH depends only on initial and final states.'],
                [$hess, 'easy', 'Enthalpy is described as a:', ['State function', 'Path function', 'Rate constant', 'Colligative property'], 0, 'State functions depend only on the current state, not how it was reached.'],
                [$hess, 'medium', 'If a chemical equation is multiplied by 2, its ΔH:', ['Is also multiplied by 2', 'Stays the same', 'Is divided by 2', 'Changes sign'], 0, 'Enthalpy is extensive — doubling amounts doubles ΔH.'],
                [$hess, 'medium', "Hess's Law is especially useful because it allows us to:", ['Find ΔH for reactions that are difficult to measure directly', 'Measure reaction rates', 'Predict the color of products', 'Determine molar mass'], 0, 'Known ΔH values of other reactions can be combined to obtain an unknown ΔH.'],
                [$hess, 'medium', 'Given A → B, ΔH = −50 kJ and B → C, ΔH = +20 kJ, what is ΔH for A → C?', ['−30 kJ', '−70 kJ', '+30 kJ', '+70 kJ'], 0, 'Add the steps: −50 + 20 = −30 kJ.'],
                [$hess, 'hard', 'Given C + O₂ → CO₂ (ΔH = −393.5 kJ) and CO + ½O₂ → CO₂ (ΔH = −283.0 kJ), what is ΔH for C + ½O₂ → CO?', ['−110.5 kJ', '−676.5 kJ', '+110.5 kJ', '−393.5 kJ'], 0, 'Keep the first equation and reverse the second: −393.5 + 283.0 = −110.5 kJ.'],
                [$hess, 'hard', 'A reaction has ΔH = +40 kJ. What is ΔH if the reaction is reversed and doubled?', ['−80 kJ', '+80 kJ', '−40 kJ', '−20 kJ'], 0, 'Reversing changes the sign (−40 kJ), doubling multiplies by 2 (−80 kJ).'],
                [$hess, 'hard', 'Using standard enthalpies of formation, ΔH°rxn equals:', ['Σ n ΔH°f(products) − Σ m ΔH°f(reactants)', 'Σ ΔH°f(reactants) − Σ ΔH°f(products)', 'Σ ΔH°f(products) × Σ ΔH°f(reactants)', 'ΔH°f of the products only'], 0, 'This form of Hess\'s Law sums formation enthalpies weighted by stoichiometric coefficients.'],
            ],
        ];
    }
}
