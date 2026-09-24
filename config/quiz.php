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

];
