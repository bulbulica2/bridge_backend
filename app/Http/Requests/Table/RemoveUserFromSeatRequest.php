<?php

namespace App\Http\Requests\Table;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RemoveUserFromSeatRequest extends FormRequest
{
  /**
   * Taking your own seat away is quitting and always allowed; taking
   * somebody else's is a kick, which only a table manager may do — except a
   * robot's at an unattended table, which anyone may, and an admin's, which
   * only another admin may (`TablePolicy::kick`). A manager aiming at
   * themselves quits rather than kicks themselves out of their own table.
   */
  public function authorize(): Response
  {
    // the policy's own message on a 403
    return Gate::inspect('kick', [$this->route('table'), $this->route('user')]);
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [];
  }
}
