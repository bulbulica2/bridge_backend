<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\IllegalClaimException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\MakeClaimRequest;
use App\Http\Requests\Game\RespondToClaimRequest;
use App\Models\Table;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Claims and concessions during the play. Every action answers with the
 * game state as `GET /tables/{table}/playing` serves it.
 */
class ClaimController extends BaseController
{
  /**
   * Claim `tricks` of the tricks still to play for the caller's side
   * (0 concedes them all).
   */
  public function store(
    MakeClaimRequest $request,
    Table $table,
    ClaimService $claims,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $claims->claim($table, $request->user(), (int) $request->validated('tricks'));
    } catch (IllegalClaimException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Claim made successfully.', 201);
  }

  /**
   * Accept or reject the pending claim.
   */
  public function respond(
    RespondToClaimRequest $request,
    Table $table,
    ClaimService $claims,
    PlayingStateService $state
  ): JsonResponse {
    $accept = $request->boolean('accept');

    try {
      $finished = $claims->respond($table, $request->user(), $accept);
    } catch (IllegalClaimException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $message = match (true) {
      ! $accept => 'Claim rejected: play goes on.',
      $finished => 'Claim accepted: the board is finished.',
      default => 'Claim accepted.',
    };

    return $this->sendResponse($state->stateFor($table, $request->user()), $message);
  }

  /**
   * The claimer withdraws their pending claim.
   */
  public function destroy(Request $request, Table $table, ClaimService $claims, PlayingStateService $state): JsonResponse
  {
    $this->authorize('play', $table);

    try {
      $claims->withdraw($table, $request->user());
    } catch (IllegalClaimException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Claim withdrawn: play goes on.');
  }
}
