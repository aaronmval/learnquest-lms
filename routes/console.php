<?php

use Database\Seeders\SampleAnalyticsSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('learnquest:sample-data {--clean : Only remove the sample data}', function () {
    $seeder = app(SampleAnalyticsSeeder::class)->setContainer(app())->setCommand($this);

    $this->option('clean') ? $seeder->clean() : $seeder->run();
})->purpose('Seed (or --clean) sample students and quiz data for the professor dashboard demo');
