<?php

namespace App\Http\Requests\Table;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class RemoveUserFromSeatRequest extends FormRequest
{
  /**
   * Taking your own seat away is quitting and always allowed; taking
   * somebody else's is a kick, which only a table manager may do — except a
   * robot's at an unattended table, which anyone may (`TablePolicy::kick`).
   * A manager aiming at themselves quits rather than kicks themselves out of
   * their own table.
   */
  public function authorize(): bool
  {
    return $this->user()->can('kick', [$this->route('table'), $this->route('user')]);
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
    throw new AuthorizationException('Only the table creator, its moderator or an admin can remove other players.');
  }
}
