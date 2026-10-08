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
      'set_minutes' => ['required_without:allow_kibitzers', 'integer', Rule::in(Table::SET_MINUTES)],
      // whether people without a seat may watch; off sends the ones watching away
      'allow_kibitzers' => ['required_without:set_minutes', 'boolean'],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException("Only the table moderator or an admin can change the table's settings.");
  }
}
