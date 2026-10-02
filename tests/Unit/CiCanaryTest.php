<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CiCanaryTest extends TestCase
{
  public function test_ci_goes_red(): void
  {
    $badly=1;
    $this->assertSame(2, $badly);
  }
}
