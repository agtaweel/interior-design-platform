<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostClientMessageRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Models\ClientUser;
use App\Models\Conversation;
use App\Models\Organization;
use App\Services\Chat\ConversationService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/POST /client/conversations, GET /client/conversations/{conversation}/messages,
 * POST /client/conversations/{conversation}/messages (BRD v4 "Client Marketplace"). Runs under
 * `client.user`, outside the `tenant` middleware (see EnsureClientUser's docblock) — there is no
 * OrganizationScope protection here, so every lookup below is manually filtered to
 * `client_user_id = $request->user()->id`, the authenticated client's own id. That filter alone
 * is sufficient: a ClientUser can never supply another client's id, so there's no separate
 * "ownership resolver" needed for this controller the way Phase C's project/deal endpoints will
 * need one.
 */
class ClientConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        $conversations = Conversation::query()
            ->where('client_user_id', $clientUser->id)
            ->with(['organization', 'messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->summary($c)),
        ]);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        $organization = Organization::query()
            ->whereHas('profile', fn ($q) => $q->where('is_marketplace_listed', true))
            ->find($request->validated('organization_id'));

        if (! $organization) {
            return $this->notFound();
        }

        $conversation = $this->conversations->startConversation($clientUser, $organization);

        return response()->json(['data' => $this->summary($conversation->fresh('organization'))], 201);
    }

    public function messages(Request $request, string $conversation): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        $model = Conversation::query()->where('client_user_id', $clientUser->id)->find($conversation);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => $model->messages->map(fn ($m) => $this->messagePayload($m)),
        ]);
    }

    public function postMessage(PostClientMessageRequest $request, string $conversation): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        $model = Conversation::query()->where('client_user_id', $clientUser->id)->with('organization')->find($conversation);

        if (! $model) {
            return $this->notFound();
        }

        $message = $this->conversations->postMessage($model, 'client', $clientUser->id, $request->validated('body'));

        $this->notifications->notifyOrganization($model->organization, 'new_inquiry_message', [
            'conversation_id' => $model->id,
            'client_name' => $clientUser->name,
            'summary' => str($message->body)->limit(80)->toString(),
        ]);

        return response()->json(['data' => $this->messagePayload($message)], 201);
    }

    private function summary(Conversation $conversation): array
    {
        $lastMessage = $conversation->relationLoaded('messages') ? $conversation->messages->first() : null;

        return [
            'id' => $conversation->id,
            'organization' => [
                'id' => $conversation->organization->id,
                'name' => $conversation->organization->name,
                'logo_url' => $conversation->organization->logo_url,
            ],
            'status' => $conversation->status,
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage) : null,
            'updated_at' => $conversation->updated_at,
        ];
    }

    private function messagePayload($message): array
    {
        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'created_at' => $message->created_at,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
                'details' => (object) [],
            ],
        ], 404);
    }
}
