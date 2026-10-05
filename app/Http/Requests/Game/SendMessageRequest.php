<?php

namespace App\Http\Requests\Game;

use App\Models\BoardMessage;
use App\Services\PlayingStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendMessageRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()->can('play', $this->route('table'));
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [
      // plain text, trimmed (TrimStrings), so a blank one is missing
      'body' => ['required', 'string', 'max:'.BoardMessage::BODY_MAX],
      'to' => ['required', 'string', Rule::in(BoardMessage::TO)],
      // the call the message is about, its place in the auction from 0
      'call_index' => ['nullable', 'integer', 'min:0'],
      // or the card it is about, its place in the play from 0
      'card_index' => ['nullable', 'integer', 'min:0', 'prohibits:call_index'],
    ];
  }

  /**
   * `call_index` must be a call of the current board's auction, and
   * `card_index` a card already played.
   *
   * @return list<callable>
   */
  public function after(): array
  {
    return [
      function (Validator $validator) {
        $playing = app(PlayingStateService::class)->currentPlaying($this->route('table'));

        if (! $validator->errors()->has('call_index') && $this->input('call_index') !== null) {
          $index = (int) $this->input('call_index');

          if ($index >= ($playing?->auctions->count() ?? 0)) {
            $validator->errors()->add('call_index', "There is no call $index in the auction.");
          }
        }

        if (! $validator->errors()->has('card_index') && $this->input('card_index') !== null) {
          $index = (int) $this->input('card_index');

          if ($index >= ($playing?->cardPlays->count() ?? 0)) {
            $validator->errors()->add('card_index', "There is no card $index in the play.");
          }
        }
      },
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the players seated at this table can chat there.');
  }
}
