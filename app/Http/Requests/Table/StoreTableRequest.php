<?php

namespace App\Http\Requests\Table;

use App\auxiliary\Seats;
use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
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
      'name' => ['nullable', 'string', 'max:'.Table::NAME_MAX],
      'seat' => ['sometimes', 'string', Rule::in(Seats::SEATS)],
      // robots take the other three seats, and the first board is dealt at once
      'robots' => ['sometimes', 'boolean'],
    ];
  }
}
