<?php

namespace App\Http\Requests\Table;

use App\Models\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()->can('manage', $this->route('table'));
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [
      // each player's time for a set, in minutes, from the next set on
      'set_minutes' => ['required', 'integer', Rule::in(Table::SET_MINUTES)],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException("Only the table moderator or an admin can change the table's settings.");
  }
}
