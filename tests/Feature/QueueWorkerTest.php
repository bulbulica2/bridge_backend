<?php

namespace Tests\Feature;

use App\Services\QueueHealthService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * What `queue:work` tells about itself: why it stopped (`LogWorkerStopping`),
 * its memory after each job (`LogJobMemory`, when asked for) and whether it
 * is running at all (its heartbeat, `GET /api/health`).
 */
class QueueWorkerTest extends TestCase
{
  public function test_a_worker_stopping_logs_why_and_its_memory(): void
  {
    Log::spy();

    event(new WorkerStopping(12, new WorkerOptions(memory: '128')));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Queue worker stopped (exit code 12).'
      && $context['status'] === 12
      && $context['reason'] === 'its PHP memory reached --memory'
      && $context['memory_mb'] > 0
      && $context['peak_memory_mb'] >= $context['memory_mb']
      && $context['memory_limit_mb'] === 128);
  }

  public function test_an_unknown_exit_code_is_still_logged(): void
  {
    Log::spy();

    event(new WorkerStopping(3));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'unknown' && $context['memory_limit_mb'] === null);
  }

  public function test_job_memory_is_logged_only_when_asked_for(): void
  {
    Log::spy();

    dispatch(fn () => null);
    Log::shouldNotHaveReceived('debug');

    config(['bridge.log_job_memory' => true]);
    dispatch(fn () => null);

    Log::shouldHaveReceived('debug')->once()->withArgs(fn (string $message, array $context) => str_starts_with($message, 'Queue job processed: Closure')
      && $context['memory_mb'] > 0
      && $context['used_mb'] > 0);
  }

  public function test_health_says_no_worker_has_run(): void
  {
    $this->getJson('/api/health')
      ->assertOk()
      ->assertJsonPath('data.queue', ['running' => false, 'last_seen_at' => null])
      ->assertJsonPath('message', 'The queue worker is not running: robots, live updates, claim expiry and the next deal wait for it.');
  }

  public function test_a_looping_worker_reads_as_running_until_its_heartbeat_goes_stale(): void
  {
    $this->freezeSecond();
    $started = now()->toIso8601ZuluString();
    $this->loop();

    $this->getJson('/api/health')
      ->assertOk()
      ->assertJsonPath('data.queue', ['running' => true, 'last_seen_at' => $started])
      ->assertJsonPath('message', 'The queue worker is running.');

    // a beat at most every ten seconds, however often it loops
    $this->travel(QueueHealthService::BEAT_SECONDS - 1)->seconds();
    $this->loop();
    $this->assertSame($started, $this->getJson('/api/health')->json('data.queue.last_seen_at'));

    $this->travel(1)->seconds();
    $this->loop();
    $this->assertSame(now()->toIso8601ZuluString(), $this->getJson('/api/health')->json('data.queue.last_seen_at'));

    // stopped: no beat for a minute
    $this->travel(QueueHealthService::STALE_SECONDS)->seconds();
    $this->getJson('/api/health')->assertJsonPath('data.queue.running', false);
  }

  public function test_a_failing_cache_never_stops_the_worker(): void
  {
    Exceptions::fake();
    Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('cache down'));

    $this->loop();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache down');
  }

  /**
   * What `queue:work` fires before each look at the queue; a listener's
   * false would pause it.
   */
  private function loop(): void
  {
    $this->assertNotContains(false, event(new Looping('database', 'default')));
  }
}
