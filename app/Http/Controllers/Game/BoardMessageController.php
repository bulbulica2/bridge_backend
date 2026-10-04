<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\IllegalMessageException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\SendMessageRequest;
use App\Http\Resources\BoardMessageResource;
use App\Models\Table;
use App\Services\BoardChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardMessageController extends BaseController
{
  /**
   * The current board's chat as the caller may read it, oldest first:
   * `playing_id` (null while the table has no board) and `messages`.
   */
  public function index(Request $request, Table $table, BoardChatService $chat): JsonResponse
  {
    $this->authorize('play', $table);

    [$playing, $messages] = $chat->messagesFor($table, $request->user());

    return $this->sendResponse([
      'playing_id' => $playing?->id,
      'messages' => BoardMessageResource::collection($messages),
    ], 'Messages retrieved successfully.');
  }

  /**
   * Send a message to the opponents or, between boards, to the whole
   * table. Answers with the message.
   */
  public function store(SendMessageRequest $request, Table $table, BoardChatService $chat): JsonResponse
  {
    try {
      $message = $chat->send(
        $table,
        $request->user(),
        $request->validated('body'),
        $request->validated('to'),
        $request->validated('call_index'),
      );
    } catch (IllegalMessageException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse(new BoardMessageResource($message), 'Message sent.', 201);
  }
}
