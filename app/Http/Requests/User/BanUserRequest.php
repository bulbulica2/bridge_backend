<?php

namespace App\Http\Requests\User;

use App\Models\UserBan;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An admin banning the user in the URL for `days` days, telling them why.
 * Who may ban whom is `UserPolicy::ban`, checked before validation.
 */
class BanUserRequest extends FormRequest
{
  public function authorize(): Response
  {
    // the policy's own message (yourself, an admin, a robot) on a 403
    return Gate::inspect('ban', $this->route('user'));
  }

  /**
   * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
   */
  public function rules(): array
  {
    return [
      'days' => ['required', 'integer', 'min:1', 'max:'.UserBan::MAX_DAYS],
      'reason' => ['required', 'string', 'max:1000'],
    ];
  }
}
