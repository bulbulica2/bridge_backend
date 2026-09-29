<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A logged-in user looking another one up by username or name, e.g. a table
 * manager picking someone to seat.
 */
class SearchUsersRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [
      'search' => ['required', 'string', 'min:2', 'max:255'],
    ];
  }
}
