<?php

namespace App\Http\Requests\Game;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class NextBoardRequest extends FormRequest
{
  /**
   * A seated player asks for themselves, and only for themselves: nobody,
   * not a moderator nor an admin, asks on another player's behalf. A stale
   * client's `everyone` is ignored.
   */
  public function authorize(): bool
  {
    return $this->user()->can('play', $this->route('table'));
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the players seated at this table can ask for the next board.');
  }
}
