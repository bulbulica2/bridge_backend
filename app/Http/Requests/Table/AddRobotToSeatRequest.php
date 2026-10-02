<?php

namespace App\Http\Requests\Table;

use App\auxiliary\Seats;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRobotToSeatRequest extends FormRequest
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
      'seat' => ['required', 'string', Rule::in(Seats::SEATS)],
    ];
  }

  protected function failedAuthorization(): void
  {
    throw new AuthorizationException('Only the table moderator or an admin can seat a robot.');
  }
}
