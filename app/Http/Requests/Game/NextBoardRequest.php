<?php

namespace App\Http\Requests\Game;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class NextBoardRequest extends FormRequest
{
  /**
   * A seated player asks for themselves; asking for `everyone` is a
   * manager's call, whether or not they sit there (an admin may not).
   */
  public function authorize(): bool
  {
    $table = $this->route('table');

    return $this->boolean('everyone')
      ? $this->user()->can('manage', $table)
      : $this->user()->can('play', $table);
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [
      'everyone' => ['sometimes', 'boolean'],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException(
      $this->boolean('everyone')
        ? 'Only a manager of this table can move everyone on to the next board.'
        : 'Only the players seated at this table can ask for the next board.'
    );
  }
}
