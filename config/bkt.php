<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bayesian Knowledge Tracing defaults
    |--------------------------------------------------------------------------
    |
    | Default P(L0)/P(T)/P(G)/P(S) parameters seeded for every new
    | (student, competency) mastery record. Configurable here rather than
    | hardcoded in the service, per the project's BKT implementation guide.
    |
    */

    'default_pl0' => (float) env('BKT_DEFAULT_PL0', 0.30),
    'default_pt' => (float) env('BKT_DEFAULT_PT', 0.15),
    // ~1/4, matching the guess rate of a typical 4-choice MCQ.
    'default_pg' => (float) env('BKT_DEFAULT_PG', 0.25),
    'default_ps' => (float) env('BKT_DEFAULT_PS', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Adaptive learning thresholds
    |--------------------------------------------------------------------------
    |
    | Not yet consumed by anything in this slice — scaffolded here so a
    | future adaptive-learning service has one configurable home for these
    | instead of hardcoding them later.
    |
    */

    'mastery_threshold_low' => (float) env('BKT_THRESHOLD_LOW', 0.40),
    'mastery_threshold_high' => (float) env('BKT_THRESHOLD_HIGH', 0.75),

];
