<?php

namespace App\Http\Requests\Game;

use App\Services\CardPlayService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class MakeClaimRequest extends FormRequest
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
      // 0 concedes; whether that many tricks are still to play is the service's call
      'tricks' => ['required', 'integer', 'min:0', 'max:'.CardPlayService::TRICKS],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the players seated at this table can claim.');
  }
}
