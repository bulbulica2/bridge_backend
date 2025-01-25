<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
  use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

  protected function sendResponse($data, $message = 'Success', $code = 200): JsonResponse
  {
    return response()->json([
      'status' => $code,
      'message' => $message,
      'data' => $data,
    ], $code);
  }
}
