<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = $request->user()->notifications()
            ->when($request->boolean('non_lues'), fn ($notifications) => $notifications->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $page = $query->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'message' => 'Notifications récupérées.',
            'data' => NotificationResource::collection($page->getCollection())->resolve($request),
            'meta' => $this->meta($page),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Compteur récupéré.',
            'data' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $markedCount = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marquées comme lues.',
            'data' => ['marquees_lues' => $markedCount],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();
        if (! $notification instanceof DatabaseNotification) {
            return response()->json(['message' => 'La notification demandée n’existe pas.', 'code' => 'NOT_FOUND'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'data' => (new NotificationResource($notification->refresh()))->resolve($request),
        ]);
    }

    /** @return array{current_page: int, per_page: int, total: int, last_page: int} */
    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ];
    }
}
