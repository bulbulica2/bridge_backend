<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Jobs\SolveDoubleDummyTable;
use App\Jobs\SolveOpeningLeads;
use App\Models\Board;
use App\Models\BoardDoubleDummy;
use App\Models\BoardLeadAnalysis;
use App\Models\BoardTable;
use App\Models\Card;
use App\Solvers\DoubleDummySolver;

/**
 * Double dummy analysis: how many tricks each hand could take with every
 * card in view and best play all round. Two things are solved and stored,
 * each once, always in the queue (never in a request):
 *
 * - a board's **table** (`board_double_dummy`), declarer's tricks for each
 *   declarer and strain: it depends only on the deal, so it is queued when
 *   the board is dealt (`queueTable()`, from `BoardSelectionService`);
 * - the **opening leads** for one declarer and strain (`board_lead_analyses`),
 *   declarer's tricks after each card the leader could lead: queued when a
 *   playing with that contract finishes (`queueLeads()`, from
 *   `BoardTable::finish()`), and shared by every playing that reaches it.
 *
 * Nothing is solved unless a `DoubleDummySolver` is bound, which
 * `AppServiceProvider` does only when `bridge.dds_library` is set: without
 * one the analysis is `unavailable`. Reading it queues whatever is still
 * missing (a board dealt before this existed, or a job that failed), so it
 * fills in by itself. Show it only to players who have finished the board
 * (`BoardPolicy::view`): before that it gives the cards away.
 */
class DoubleDummyService
{
  public const READY = 'ready';

  public const PENDING = 'pending';

  public const UNAVAILABLE = 'unavailable';

  public function __construct(private PlayingStateService $state) {}

  /**
   * Whether there is a solver to run.
   */
  public function available(): bool
  {
    return app()->bound(DoubleDummySolver::class);
  }

  /**
   * Queue the board's table, unless it is stored already. Dispatched after
   * the commit, so a rolled back deal solves nothing.
   */
  public function queueTable(Board $board): void
  {
    if ($this->available() && ! $board->doubleDummy()->exists()) {
      SolveDoubleDummyTable::dispatch($board->id)->afterCommit();
    }
  }

  /**
   * Queue the opening lead analysis for a finished playing's contract,
   * unless it is stored already. Nothing for a passed out board.
   */
  public function queueLeads(BoardTable $playing): void
  {
    if (! $this->available() || $playing->contractBid === null) {
      return;
    }

    $strain = $playing->contractBid->strain;

    if ($this->leadAnalysis($playing->board_id, $playing->declarer_seat, $strain) === null) {
      SolveOpeningLeads::dispatch($playing->board_id, $playing->declarer_seat, $strain)->afterCommit();
    }
  }

  /**
   * Solve and store the board's table, if nobody has yet. Run by
   * `SolveDoubleDummyTable`.
   */
  public function solveTable(Board $board): void
  {
    if ($board->doubleDummy()->exists()) {
      return;
    }

    $tricks = app(DoubleDummySolver::class)->table($this->hands($board));

    // another worker may have stored it meanwhile: keep theirs
    BoardDoubleDummy::createOrFirst(['board_id' => $board->id], ['tricks' => $tricks]);
  }

  /**
   * Solve and store the opening leads for a declarer and strain on the
   * board, if nobody has yet: one entry per card in the leader's hand, in
   * hand order. Run by `SolveOpeningLeads`.
   */
  public function solveLeads(Board $board, string $declarer, string $strain): void
  {
    if ($this->leadAnalysis($board->id, $declarer, $strain) !== null) {
      return;
    }

    $tricks = app(DoubleDummySolver::class)->leads($this->hands($board), $declarer, $strain);

    $leads = array_map(fn ($card) => [
      'card_id' => $card['id'],
      'tricks' => $tricks[$card['id']],
    ], $this->state->boardDeal($board)[Seats::next($declarer)]);

    BoardLeadAnalysis::createOrFirst(
      ['board_id' => $board->id, 'declarer_seat' => $declarer, 'strain' => $strain],
      ['leads' => $leads]
    );
  }

  /**
   * The board's table for `GET /boards/{board}/double-dummy`: `status`
   * (`ready`, `pending` while the job hasn't run, `unavailable` with no
   * solver) and `table`, null until it is ready.
   *
   * @return array{status: string, table: array<string, array<string, int>>|null}
   */
  public function forBoard(Board $board): array
  {
    $table = $board->doubleDummy()->first()?->tricks;

    if ($table === null) {
      $this->queueTable($board);
    }

    return [
      'status' => $table !== null ? self::READY : $this->missing(),
      'table' => $table,
    ];
  }

  /**
   * A finished playing's analysis, for its review: the board's `table` and
   * `leads`, each card the opening leader could have led
   * (`{card, tricks}`, declarer's tricks after it) for the playing's
   * declarer and strain. `leads` is null on a passed out board, where
   * `status` is `ready` with the table alone.
   *
   * @return array{status: string, table: array<string, array<string, int>>|null, leads: list<array{card: array<string, mixed>, tricks: int}>|null}
   */
  public function forPlaying(BoardTable $playing): array
  {
    $board = $playing->board;
    ['status' => $status, 'table' => $table] = $this->forBoard($board);

    if ($playing->contractBid === null) {
      return ['status' => $status, 'table' => $table, 'leads' => null];
    }

    $analysis = $this->leadAnalysis($board->id, $playing->declarer_seat, $playing->contractBid->strain);

    if ($analysis === null) {
      $this->queueLeads($playing);
      $status = $this->missing();
    }

    return ['status' => $status, 'table' => $table, 'leads' => $analysis === null ? null : $this->leadCards($analysis->leads)];
  }

  /**
   * What is missing is on its way when there is a solver, and never comes
   * without one.
   */
  private function missing(): string
  {
    return $this->available() ? self::PENDING : self::UNAVAILABLE;
  }

  private function leadAnalysis(int $boardId, string $declarer, string $strain): ?BoardLeadAnalysis
  {
    return BoardLeadAnalysis::where([
      'board_id' => $boardId,
      'declarer_seat' => $declarer,
      'strain' => $strain,
    ])->first();
  }

  /**
   * @param  list<array{card_id: int, tricks: int}>  $leads
   * @return list<array{card: array<string, mixed>, tricks: int}>
   */
  private function leadCards(array $leads): array
  {
    $cards = Card::findMany(array_column($leads, 'card_id'))->keyBy('id');

    return array_map(fn ($lead) => [
      'card' => PlayingStateService::card($cards[$lead['card_id']]),
      'tricks' => $lead['tricks'],
    ], $leads);
  }

  /**
   * The four hands as dealt, as the solver takes them.
   *
   * @return array<string, list<Card>>
   */
  private function hands(Board $board): array
  {
    $cards = $board->cards()->get();
    $hands = [];

    foreach (Seats::SEATS as $seat) {
      $hands[$seat] = $cards->filter(fn ($card) => $card->pivot->seat === $seat)->values()->all();
    }

    return $hands;
  }
}
