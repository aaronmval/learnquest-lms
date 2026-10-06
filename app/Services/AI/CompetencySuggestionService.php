<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Exceptions\AI\NoSourceContentException;
use App\Models\Module;
use App\Models\Subject;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Documents\StoredFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suggests competencies for a subject from the text of its uploaded modules.
 * Llama only proposes them — nothing is saved here. The professor reviews
 * the list and adds the ones they want through the normal competency
 * endpoints, so competencies stay teacher-approved.
 */
class CompetencySuggestionService
{
    /** Bounds on how many suggestions are asked for; the target grows with the number of modules. */
    public const MIN_SUGGESTION_TARGET = 8;

    public const MAX_SUGGESTIONS = 25;

    private const MAX_MODULES = 30;

    /** Total module text sent to the model, shared between the modules. */
    private const TOTAL_EXCERPT_CHARS = 24000;

    private const MIN_EXCERPT_CHARS = 500;

    private const MAX_EXCERPT_CHARS = 4000;

    /** Stop parsing further PDFs after this long, so the request stays well inside PHP's web time limit. */
    private const EXTRACTION_BUDGET_SECONDS = 40;

    private const MAX_NAME_CHARS = 120;

    private const MAX_DESCRIPTION_CHARS = 300;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private PdfTextExtractorService $extractor,
    ) {
    }

    /**
     * @return array{suggestions: array<int, array{name:string, description:?string, modules:array<int, string>}>, modules_total:int, modules_read:int, model:string}
     *
     * @throws NoSourceContentException
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    public function suggestFor(Subject $subject): array
    {
        $modules = $subject->modules()->get()
            ->sort(fn (Module $a, Module $b) => strnatcasecmp($a->title, $b->title))
            ->take(self::MAX_MODULES)
            ->values();

        if ($modules->isEmpty()) {
            throw new NoSourceContentException('Upload at least one module to this subject first, then try again.');
        }

        $sources = $this->moduleSources($modules);
        $modulesRead = count(array_filter($sources, fn ($s) => $s['excerpt'] !== null));

        if ($modulesRead === 0) {
            throw new NoSourceContentException(
                "We couldn't read any text from this subject's modules. Scanned or image-only PDFs can't be used for suggestions."
            );
        }

        $existingNames = $subject->competencies()->pluck('name')->all();
        $target = (int) max(self::MIN_SUGGESTION_TARGET, min(self::MAX_SUGGESTIONS, $modules->count() + 2));
        $messages = $this->prompts->competencySuggestionMessages($subject->name, $sources, $existingNames, $target);
        $limits = array_map('intval', (array) config('services.routeway.competency_suggestions', []));
        $options = $limits + ['max_tokens' => 3000, 'temperature' => 0.3];
        $titles = array_column($sources, 'title');

        $result = $this->llama->chat($messages, $options);

        try {
            $suggestions = $this->parseAndValidate($result['content'], $existingNames, $titles);
        } catch (InvalidAiResponseException $e) {
            $this->logInvalid($subject, $result, $e);

            // Only re-ask when the primary model wrote it: a reply from a
            // fallback model already came after the primary failed, and
            // re-running the fallback chain would overrun the time budget.
            $hasFallback = ! empty(config('services.routeway.fallback_model')) || ! empty(config('services.routeway.last_resort_model'));
            if (! $hasFallback || $result['model'] !== config('services.routeway.model')) {
                throw $e;
            }

            // The primary model answered but not in a usable shape — ask the fallback models once.
            $result = $this->llama->chat($messages, $options + ['fallback_only' => true]);

            try {
                $suggestions = $this->parseAndValidate($result['content'], $existingNames, $titles);
            } catch (InvalidAiResponseException $e) {
                $this->logInvalid($subject, $result, $e);

                throw $e;
            }
        }

        return [
            'suggestions' => $suggestions,
            'modules_total' => $modules->count(),
            'modules_read' => $modulesRead,
            'model' => $result['model'],
        ];
    }

    /**
     * Keep only well-formed, new suggestions: a non-empty name that isn't
     * already a competency of the subject (or repeated in the reply).
     *
     * @param  array<int, string>  $existingNames
     * @param  array<int, string>  $moduleTitles
     * @return array<int, array{name:string, description:?string, modules:array<int, string>}>
     *
     * @throws InvalidAiResponseException
     */
    public function parseAndValidate(string $raw, array $existingNames, array $moduleTitles): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded) || ! is_array($decoded['competencies'] ?? null)) {
            throw new InvalidAiResponseException('AI response was not valid competency suggestions JSON.');
        }

        $taken = array_fill_keys(array_map(fn ($name) => $this->nameKey($name), $existingNames), true);
        $titlesByKey = [];
        foreach ($moduleTitles as $title) {
            $titlesByKey[Str::lower(trim($title))] = $title;
        }

        $suggestions = [];

        foreach ($decoded['competencies'] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                continue;
            }

            $name = trim(preg_replace('/\s+/', ' ', $item['name']));
            $key = $this->nameKey($name);

            if ($name === '' || mb_strlen($name) > self::MAX_NAME_CHARS || isset($taken[$key])) {
                continue;
            }

            $description = is_string($item['description'] ?? null) ? trim(preg_replace('/\s+/', ' ', $item['description'])) : '';

            $sourceModules = [];
            foreach ((array) ($item['modules'] ?? []) as $title) {
                $known = is_string($title) ? ($titlesByKey[Str::lower(trim($title))] ?? null) : null;
                if ($known !== null && ! in_array($known, $sourceModules, true)) {
                    $sourceModules[] = $known;
                }
            }

            $taken[$key] = true;
            $suggestions[] = [
                'name' => $name,
                'description' => $description === '' ? null : Str::limit($description, self::MAX_DESCRIPTION_CHARS),
                'modules' => $sourceModules,
            ];

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        if (empty($suggestions)) {
            throw new InvalidAiResponseException('AI response contained no new, valid competencies.');
        }

        return $suggestions;
    }

    /**
     * Title plus a text excerpt for each module. `excerpt` is null when the
     * PDF has no readable text or the extraction time budget ran out.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, array{title:string, description:?string, excerpt:?string}>
     */
    private function moduleSources(Collection $modules): array
    {
        $perModule = (int) max(
            self::MIN_EXCERPT_CHARS,
            min(self::MAX_EXCERPT_CHARS, intdiv(self::TOTAL_EXCERPT_CHARS, $modules->count())),
        );
        $startedAt = microtime(true);

        return $modules->map(function (Module $module) use ($perModule, $startedAt) {
            $text = $this->textFor($module, microtime(true) - $startedAt < self::EXTRACTION_BUDGET_SECONDS);

            return [
                'title' => $module->title,
                'description' => filled($module->description) ? Str::limit(trim($module->description), 200) : null,
                'excerpt' => $text === null ? null : $this->excerpt($text, $perModule),
            ];
        })->all();
    }

    /**
     * Extracted text of a module's PDF, cached per file. Null when it has no
     * readable text, or when it isn't cached yet and $mayExtract is false.
     */
    private function textFor(Module $module, bool $mayExtract): ?string
    {
        $key = "module-text:{$module->id}:".md5($module->file_path);
        $text = Cache::get($key);

        if ($text === null) {
            if (! $mayExtract) {
                return null;
            }

            // Failures cache as '' so an unreadable (e.g. scanned) PDF isn't re-parsed every time.
            try {
                $text = StoredFile::withLocalPath(
                    $module->file_path,
                    fn (string $path) => $this->extractor->extractText($path),
                );
            } catch (Throwable $e) {
                Log::channel('ai')->warning('[competency-suggestions] Could not extract module text.', [
                    'module_id' => $module->id,
                    'error' => $e->getMessage(),
                ]);

                $text = '';
            }

            Cache::forever($key, $text);
        }

        // PDF text can carry invalid byte sequences, which would make the
        // whole request body unencodable as JSON.
        $text = trim(preg_replace('/[^\P{C}\n\t]+/u', ' ', mb_scrub($text, 'UTF-8')) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * A slice from the opening plus a larger one from further in, since the
     * first pages of a module are often cover and front matter.
     */
    private function excerpt(string $text, int $chars): string
    {
        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        $head = (int) floor($chars * 0.35);
        $bodyStart = max($head, (int) floor(mb_strlen($text) * 0.25));

        return mb_substr($text, 0, $head)."\n[...]\n".mb_substr($text, $bodyStart, $chars - $head);
    }

    private function nameKey(string $name): string
    {
        return Str::lower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name)));
    }

    private function logInvalid(Subject $subject, array $result, InvalidAiResponseException $e): void
    {
        Log::channel('ai')->error('[competency-suggestions] AI response failed validation.', [
            'subject_id' => $subject->id,
            'model' => $result['model'],
            'reason' => $e->getMessage(),
            'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $result['content']), 2000),
        ]);
    }
}
