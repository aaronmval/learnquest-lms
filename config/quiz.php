<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI quiz generation limits
    |--------------------------------------------------------------------------
    */

    // Kept modest by default — each question (4 choices + explanation) adds
    // meaningfully to generation time, and this is a synchronous request the
    // student waits on. Raise via QUIZ_DEFAULT_QUESTION_COUNT if desired.
    'default_question_count' => (int) env('QUIZ_DEFAULT_QUESTION_COUNT', 6),
    'max_questions_per_batch' => (int) env('QUIZ_MAX_QUESTIONS_PER_BATCH', 15),
    'min_choices' => (int) env('QUIZ_MIN_CHOICES', 2),
    'max_choices' => (int) env('QUIZ_MAX_CHOICES', 6),

    // Minimum number of student feedback submissions required before a
    // professor can regenerate a quiz — ties regeneration to actually
    // having data to act on, rather than being an unconditional button.
    'min_feedback_for_regeneration' => (int) env('QUIZ_MIN_FEEDBACK_FOR_REGENERATION', 1),

    // Questions per AI request. Larger quizzes are split into batches
    // generated in parallel, so a 15-question quiz (3 batches of 5) takes
    // about as long as a 6-question one. Measured on the fallback model,
    // 8-question batches took ~57s — too close to its 60s timeout.
    'questions_per_batch' => (int) env('QUIZ_QUESTIONS_PER_BATCH', 6),

    /*
    |--------------------------------------------------------------------------
    | Teacher quiz settings (Quiz & AI Setup page)
    |--------------------------------------------------------------------------
    | Defaults for a lesson the teacher hasn't configured. Once a teacher
    | saves settings, their latest settings pre-fill their next lesson.
    */

    'min_question_count' => 5,
    'max_question_count' => 15,
    'max_time_limit_minutes' => 120,

    'default_settings' => [
        'time_limit_minutes' => null,          // null = no timer
        'difficulty_mix' => ['easy' => 30, 'medium' => 40, 'hard' => 30],
        'max_attempts' => null,                // null = unlimited
        'shuffle_questions' => true,
        'shuffle_choices' => false,
        'show_answers' => true,
        'adaptive' => true,
    ],

    // Adaptive quizzes: the teacher's difficulty mix is used for students at
    // "developing" BKT mastery. For "high" mastery this many percentage
    // points move toward "hard" (from easy first, then medium); for "low"
    // mastery they move toward "easy" (from hard first, then medium). A
    // configurable working value, not a research-fixed one.
    'adaptive_shift' => (int) env('QUIZ_ADAPTIVE_SHIFT', 20),

    // Seconds after a timed quiz's deadline during which a submission still
    // counts as on time (covers network delay on auto-submit). Later
    // submissions are still graded, but marked "late".
    'time_limit_grace_seconds' => (int) env('QUIZ_TIME_LIMIT_GRACE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Review-based AI training (difficulty calibration)
    |--------------------------------------------------------------------------
    | Expected share of students answering correctly for each difficulty
    | label. These are configurable working targets for calibrating the
    | AI's labels against real results — not research-fixed values.
    */

    'difficulty_targets' => [
        'easy' => (float) env('QUIZ_TARGET_EASY', 0.80),
        'medium' => (float) env('QUIZ_TARGET_MEDIUM', 0.60),
        'hard' => (float) env('QUIZ_TARGET_HARD', 0.40),
    ],

    // How far a question's actual % correct may drift from its label's
    // target before it is flagged as possibly mislabeled.
    'calibration_tolerance' => (float) env('QUIZ_CALIBRATION_TOLERANCE', 0.15),

    // Student responses a question needs before its % correct is trusted.
    'min_responses_for_calibration' => (int) env('QUIZ_MIN_RESPONSES_FOR_CALIBRATION', 5),

];
