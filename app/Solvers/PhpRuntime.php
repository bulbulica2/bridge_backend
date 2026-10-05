<?php

namespace App\Solvers;

/**
 * What this PHP process offers `DdsSolver`, for `dds:check`: whether the
 * FFI extension is loaded, and whether Xdebug is on. Xdebug in any mode
 * but `off` (`debug`, `develop`, `coverage`…) breaks FFI's type parsing:
 * the library loads, then its first struct throws `FFI\ParserException`.
 * Tests bind a stand-in to see each answer.
 */
class PhpRuntime
{
  public function ffiLoaded(): bool
  {
    return extension_loaded('ffi');
  }

  /**
   * Xdebug's modes in this process, none when it isn't loaded or is `off`.
   *
   * @return list<string>
   */
  public function xdebugModes(): array
  {
    if (! extension_loaded('xdebug')) {
      return [];
    }

    // XDEBUG_MODE, when set, wins over the ini setting
    $modes = explode(',', getenv('XDEBUG_MODE') ?: (string) ini_get('xdebug.mode'));

    return array_values(array_diff(array_map('trim', $modes), ['', 'off']));
  }
}
