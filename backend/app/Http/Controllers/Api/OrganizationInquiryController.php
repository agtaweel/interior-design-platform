<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostOrganizationMessageRequest;
use App\Models\Client;
use App\Models\Conversation;
use App\Services\Chat\ConversationService;
use App\Services\Marketplace\ClientUserLinkingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /inquiries, GET /inquiries/{conversation}, POST /inquiries/{conversation}/messages,
 * POST /inquiries/{conversation}/create-client (BRD v4 "Client Marketplace") — the staff-side
 * inbox for conversations marketplace clients started with this organization, plus the hand-off
 * action that turns an inquiry into a real `Client` contact record (see
 * ClientUserLinkingService's docblock). Inside the `tenant` group, so Conversation::find() is
 * already org-scoped via BelongsToOrganization's OrganizationScope — no manual ownership check
 * needed here, unlike ClientConversationController's client-side routes. Reads require only an
 * active membership (matching this codebase's default read posture); posting a reply or
 * creating the client both require Permissions::MANAGE_CLIENTS, the same tier as every other
 * client-record mutation in this codebase.
 */
class OrganizationInquiryController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ClientUserLinkingService $linkingService,
    ) {}

    public function index(): JsonResponse
    {
        $conversations = Conversation::query()
            ->with(['clientUser', 'messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->summary($c)),
        ]);
    }

    public function show(string $conversation): JsonResponse
    {
        $model = Conversation::query()->with('clientUser')->find($conversation);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                ...$this->summary($model),
                'messages' => $model->messages->map(fn ($m) => $this->messagePayload($m)),
            ],
        ]);
    }

    public function postMessage(PostOrganizationMessageRequest $request, string $conversation): JsonResponse
    {
        $model = Conversation::find($conversation);

        if (! $model) {
            return $this->notFound();
        }

        $message = $this->conversations->postMessage($model, 'org_member', $request->user()->id, $request->validated('body'));

        return response()->json(['data' => $this->messagePayload($message)], 201);
    }

    public function createClient(string $conversation): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_CLIENTS);

        $model = Conversation::query()->with(['clientUser', 'organization'])->find($conversation);

        if (! $model) {
            return $this->notFound();
        }

        $result = $this->linkingService->findOrCreateClient($model->organization, $model->clientUser);

        return response()->json([
            'data' => [
                'client' => $result['client'] ? $this->clientPayload($result['client']) : null,
                'candidates' => $result['candidates']?->map(fn (Client $c) => $this->clientPayload($c))->values(),
            ],
        ]);
    }

    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
        ];
    }

    private function summary(Conversation $conversation): array
    {
        $lastMessage = $conversation->relationLoaded('messages') ? $conversation->messages->first() : null;

        return [
            'id' => $conversation->id,
            'client' => [
                'id' => $conversation->clientUser->id,
                'name' => $conversation->clientUser->name,
                'email' => $conversation->clientUser->email,
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
