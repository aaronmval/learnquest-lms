<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestAiMessageRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AI\QuestAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class QuestAiController extends Controller
{
    private const CONVERSATION_LIST_LIMIT = 20;

    private const TITLE_CHARS = 60;

    public function context(Request $request, QuestAiService $questAi): JsonResponse
    {
        return response()->json($questAi->context($request->user()));
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = AiConversation::where('student_id', $request->user()->id)
            ->latest('last_message_at')
            ->latest('id')
            ->take(self::CONVERSATION_LIST_LIMIT)
            ->get(['id', 'class_id', 'title', 'last_message_at']);

        return response()->json($conversations);
    }

    public function showConversation(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation),
            'messages' => $conversation->messages()->orderBy('id')->get()->map(fn ($m) => $this->messagePayload($m)),
        ]);
    }

    /**
     * Send a question to QuestAI. The question is stored first so it stays
     * in the student's history even if the AI service is unavailable.
     */
    public function storeMessage(StoreQuestAiMessageRequest $request, QuestAiService $questAi): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $student = $request->user();
        $data = $request->validated();

        if (! empty($data['conversation_id'])) {
            $conversation = AiConversation::findOrFail($data['conversation_id']);
            $this->authorizeConversation($request, $conversation);
        } else {
            $classId = $data['class_id'] ?? null;

            if ($classId !== null) {
                abort_unless($student->enrolledClasses()->where('classes.id', $classId)->exists(), 404);
            }

            $conversation = AiConversation::create([
                'student_id' => $student->id,
                'class_id' => $classId,
                'title' => Str::limit(trim($data['message']), self::TITLE_CHARS),
                'last_message_at' => now(),
            ]);
        }

        $userMessage = $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => trim($data['message']),
        ]);
        $conversation->update(['last_message_at' => now()]);

        try {
            $reply = $questAi->reply($student, $conversation, $userMessage);
        } catch (LlamaApiException|InvalidAiResponseException $e) {
            Log::channel('ai')->error('[controller] QuestAI reply failed (AI service).', [
                'request_id' => $requestId,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($conversation, $userMessage, $requestId,
                'QuestAI is temporarily unavailable. Please try again in a moment.', 502);
        } catch (Throwable $e) {
            Log::channel('ai')->error('[controller] QuestAI reply failed.', [
                'request_id' => $requestId,
                'conversation_id' => $conversation->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return $this->failure($conversation, $userMessage, $requestId,
                "QuestAI couldn't answer that right now. Please try again.", 500);
        }

        return response()->json([
            'conversation' => $this->conversationPayload($conversation),
            'user_message' => $this->messagePayload($userMessage),
            'reply' => $this->messagePayload($reply),
            'request_id' => $requestId,
        ], 201);
    }

    public function feedback(Request $request, AiMessage $message): JsonResponse
    {
        $this->authorizeConversation($request, $message->conversation);
        abort_unless($message->role === AiMessage::ROLE_ASSISTANT, 422, 'Feedback can only be given on QuestAI replies.');

        $validated = $request->validate(['helpful' => ['required', 'boolean']]);

        $message->update(['feedback' => $validated['helpful'] ? 1 : -1]);

        return response()->json(['id' => $message->id, 'feedback' => $message->feedback]);
    }

    private function failure(AiConversation $conversation, AiMessage $userMessage, string $requestId, string $text, int $status): JsonResponse
    {
        return response()->json([
            'message' => $text,
            'conversation' => $this->conversationPayload($conversation),
            'user_message' => $this->messagePayload($userMessage),
            'request_id' => $requestId,
        ], $status);
    }

    private function authorizeConversation(Request $request, ?AiConversation $conversation): void
    {
        abort_unless($conversation && $conversation->student_id === $request->user()->id, 404);
    }

    private function conversationPayload(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'class_id' => $conversation->class_id,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }

    private function messagePayload(AiMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'feedback' => $message->feedback,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
