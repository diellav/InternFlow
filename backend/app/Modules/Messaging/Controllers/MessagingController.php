<?php

namespace App\Modules\Messaging\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Requests\ListMessagingRequest;
use App\Modules\Messaging\Requests\OpenConversationRequest;
use App\Modules\Messaging\Requests\ReadConversationRequest;
use App\Modules\Messaging\Requests\SendMessageRequest;
use App\Modules\Messaging\Resources\ConversationResource;
use App\Modules\Messaging\Resources\MessageResource;
use App\Modules\Messaging\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function index(ListMessagingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return ConversationResource::collection($this->messaging->listing($request->user(), $filters['filter'] ?? 'all', $filters['search'] ?? null)->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1)->withQueryString());
    }

    public function internships(ListMessagingRequest $request): JsonResponse
    {
        return response()->json($this->messaging->eligibleInternships($request->user(), (int) $request->validated('per_page', 15), (int) $request->validated('page', 1)));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->messaging->unreadCounts($request->user())]);
    }

    public function read(ReadConversationRequest $request, int $conversation): ConversationResource
    {
        return new ConversationResource($this->messaging->markRead($request->user(), $conversation, (int) $request->validated('last_read_message_id')));
    }

    public function show(Request $request, int $conversation): ConversationResource
    {
        return new ConversationResource($this->messaging->conversation($request->user(), $conversation));
    }

    public function open(OpenConversationRequest $request, int $internship): ConversationResource
    {
        return new ConversationResource($this->messaging->open($request->user(), $internship, $request->validated('type')));
    }

    public function messages(ListMessagingRequest $request, int $conversation): AnonymousResourceCollection
    {
        $record = $this->messaging->conversation($request->user(), $conversation);

        $direction = $request->validated('latest') === 'true' ? 'desc' : 'asc';

        return MessageResource::collection($record->messages()->with('sender:id,first_name,last_name')->orderBy('sent_at', $direction)->orderBy('id', $direction)->cursorPaginate($request->validated('per_page', 25))->withQueryString());
    }

    public function send(SendMessageRequest $request, int $conversation): MessageResource
    {
        return new MessageResource($this->messaging->send($request->user(), $conversation, $request->validated('content')));
    }
}
