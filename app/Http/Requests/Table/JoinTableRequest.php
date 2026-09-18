<?php

namespace App\Http\Requests\Table;

use App\auxiliary\Seats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinTableRequest extends FormRequest
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
      'seat' => ['required', 'string', Rule::in(Seats::SEATS)],
    ];
  }
}
