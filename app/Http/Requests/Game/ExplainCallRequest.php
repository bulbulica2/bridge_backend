<?php

namespace App\Http\Requests\Game;

use App\Models\Auction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class ExplainCallRequest extends FormRequest
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
      'explanation' => ['required', 'string', 'max:'.Auction::EXPLANATION_MAX],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the players seated at this table can explain a call.');
  }
}
