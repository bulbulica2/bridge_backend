<?php

namespace App\Http\Requests\Table;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class RemoveUserFromSeatRequest extends FormRequest
{
  /**
   * Taking your own seat away is quitting and always allowed; taking
   * somebody else's is a kick, which only a table manager may do. A manager
   * aiming at themselves falls in the first branch, so they quit rather than
   * kick themselves out of their own table.
   */
  public function authorize(): bool
  {
    return $this->user()->id === $this->route('user')->id
      || $this->user()->can('manage', $this->route('table'));
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
