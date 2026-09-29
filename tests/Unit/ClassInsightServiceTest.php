<?php

namespace Tests\Unit;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Services\AI\ClassInsightService;
use App\Services\AI\LlamaService;
use App\Services\AI\PromptService;
use Mockery;
use Tests\TestCase;

class ClassInsightServiceTest extends TestCase
{
    private function service(): ClassInsightService
    {
        return new ClassInsightService(Mockery::mock(LlamaService::class), new PromptService);
    }

    public function test_parse_keeps_valid_items_and_drops_invalid_ones(): void
    {
        $raw = "```json\n".json_encode(['insights' => [
            ['type' => 'weakness', 'competency_id' => 1, 'text' => '  Re-teach it.  '],
            ['type' => 'bogus', 'competency_id' => 1, 'text' => 'Dropped.'],
            ['type' => 'strength', 'competency_id' => 1, 'text' => ''],
            ['type' => 'intervention', 'competency_id' => 42, 'text' => 'Group remediation.'],
        ]])."\n```";

        $insights = $this->service()->parseAndValidate($raw, [1 => 'Stoichiometry']);

        $this->assertSame([
            ['type' => 'weakness', 'text' => 'Re-teach it.', 'competency_name' => 'Stoichiometry'],
            ['type' => 'intervention', 'text' => 'Group remediation.', 'competency_name' => null],
        ], $insights);
    }

    public function test_parse_rejects_output_with_no_valid_insights(): void
    {
        $this->expectException(InvalidAiResponseException::class);

        $this->service()->parseAndValidate(json_encode(['insights' => [['type' => 'next_step', 'text' => 'x']]]), []);
    }

    public function test_rule_based_notes_follow_bkt_levels(): void
    {
        $competencies = [
            ['id' => 1, 'name' => 'Atoms', 'mastery' => 88.0, 'level' => 'high', 'assessed_students' => 3],
            ['id' => 2, 'name' => 'Moles', 'mastery' => 22.0, 'level' => 'low', 'assessed_students' => 3],
            ['id' => 3, 'name' => 'Bonds', 'mastery' => 55.0, 'level' => 'developing', 'assessed_students' => 3],
        ];

        $insights = $this->service()->ruleBasedInsights($competencies, ['students' => 3, 'assessed' => 3, 'low' => 1, 'quiz_average' => 60.0]);

        $this->assertSame(['strength', 'weakness', 'weakness', 'intervention'], array_column($insights, 'type'));
        $this->assertSame(['Atoms', 'Moles', 'Bonds', null], array_column($insights, 'competency_name'));
    }
}
