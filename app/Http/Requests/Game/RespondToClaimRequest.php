<?php

namespace App\Http\Requests\Game;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class RespondToClaimRequest extends FormRequest
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
      'accept' => ['required', 'boolean'],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the players seated at this table can answer a claim.');
  }
}
