<?php

namespace App\Services\AI;

/**
 * Builds the message payloads sent to Llama. Keeps prompt text out of
 * controllers and services that call the model.
 */
class PromptService
{
    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function lessonSummaryMessages(string $subject, string $lessonTitle, string $content): array
    {
        $system = <<<'PROMPT'
            You are an AI tutor for Grade 11 and Grade 12 STEM students. Summarize
            the lesson content the student's teacher shared so it's easier to review.

            Respond with ONLY valid JSON, no markdown fences, no extra commentary,
            matching exactly this shape:

            {"overview": "a short paragraph recapping the lesson", "key_points": ["short point", "short point"]}

            Rules:
            - "overview" must be 2-4 sentences, plain language appropriate for a
              high school STEM student.
            - "key_points" must be 3-6 short bullet points highlighting the most
              important ideas from the lesson content.
            - Base the summary only on the lesson content provided. Do not invent
              facts that aren't supported by it.
            PROMPT;

        $user = "Subject: {$subject}\nLesson: {$lessonTitle}\n\nLesson content:\n{$content}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
