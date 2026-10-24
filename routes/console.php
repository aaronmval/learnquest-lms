<?php

use App\Models\Competency;
use App\Services\Learning\CompetencyMergeService;
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

Artisan::command('learnquest:merge-competency
    {name : Competency to remove}
    {--subject=General Chemistry 2 : Subject the competency belongs to}
    {--into= : Competency that receives its questions (default: each quiz\'s main competency)}
    {--dry-run : Only show what would change}', function (CompetencyMergeService $merger) {
    $find = function (string $name) {
        $matches = Competency::where('name', $name)
            ->whereHas('subject', fn ($q) => $q->where('name', $this->option('subject')))
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "Competency \"{$name}\" not found in subject \"{$this->option('subject')}\"."
                : "More than one subject named \"{$this->option('subject')}\" has \"{$name}\".");

            return null;
        }

        return $matches->first();
    };

    if (! ($from = $find($this->argument('name')))) {
        return 1;
    }
    $into = null;
    if ($this->option('into') && ! ($into = $find($this->option('into')))) {
        return 1;
    }

    try {
        $plan = $merger->plan($from, $into);
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->table(
        ['Quiz', 'Lesson', 'Questions', 'Answers', 'Moves to'],
        $plan->map(fn ($s) => [$s['quiz_id'], $s['lesson'], $s['questions'], $s['answers'], $s['into']->name])->all(),
    );

    if ($this->option('dry-run')) {
        $this->info('Dry run — nothing changed.');

        return 0;
    }

    $result = $merger->merge($from, $into);
    $this->info("Moved {$result['questions']} questions and {$result['answers']} answers, rebuilt {$result['mastery_rebuilt']} mastery records, and deleted \"{$from->name}\".");

    return 0;
})->purpose('Remove a competency still used by quiz questions, moving them to another competency');
