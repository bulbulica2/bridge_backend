<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The authenticated user editing their own profile. Only `name` and
 * `description` are editable here; `username`, `email` and `is_admin` are
 * not in the rules, so they never reach `validated()`.
 */
class UpdateProfileRequest extends FormRequest
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
      'name' => ['sometimes', 'required', 'string', 'max:255'],
      'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
    ];
  }
}
