<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Filters shared by the professor dashboard data and AI analysis endpoints.
 */
class ProfessorAnalyticsRequest extends FormRequest
{
    private const QUARTERS = [
        1 => '1st Quarter',
        2 => '2nd Quarter',
        3 => '3rd Quarter',
        4 => '4th Quarter',
    ];

    public function authorize(): bool
    {
        // Route middleware (auth + professor.role) already restricts this to professors;
        // section ownership is checked by ProfessorAnalyticsService.
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['nullable', 'integer'],
            'quarter' => ['nullable', 'integer', 'in:1,2,3,4'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
        ];
    }

    public function classId(): ?int
    {
        return $this->filled('class_id') ? (int) $this->validated('class_id') : null;
    }

    public function quarterName(): ?string
    {
        return $this->filled('quarter') ? self::QUARTERS[(int) $this->validated('quarter')] : null;
    }

    public function startDate(): ?Carbon
    {
        return $this->filled('start') ? Carbon::parse($this->validated('start')) : null;
    }

    public function endDate(): ?Carbon
    {
        return $this->filled('end') ? Carbon::parse($this->validated('end')) : null;
    }
}
