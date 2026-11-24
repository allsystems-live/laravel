<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Http\Middleware\PlatformMaintenanceMiddleware;
use AllSystems\Laravel\Http\Middleware\VerifySignature;
use AllSystems\Laravel\PlatformMaintenance;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

final class PlatformMaintenanceMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        assert($app !== null);

        $kernel = $app->make(HttpKernelContract::class);
        assert($kernel instanceof FoundationHttpKernel);
        $kernel->pushMiddleware(PlatformMaintenanceMiddleware::class);

        Route::get('/', static fn () => 'home');
        Route::get('/up', static fn () => 'up');
        Route::get('/status', static fn () => 'status');
        Route::post('/other', static fn () => 'other');
    }

    public function testAnOpenWindowAnswersTheHtmlPage(): void
    {
        $this->startWindow();
        config(['app.name' => 'Acme']);
        $name = 'Acme';

        $response = $this->get('/');

        $response->assertStatus(503);
        $this->assertOutageHeaders($response);
        $response->assertSee("<title>{$name} – back in a minute</title>", false);
        $response->assertSee("We're updating {$name}", false);
        $response->assertSee("We'll be back in a minute", false);
        $response->assertDontSee('http', false);
    }

    public function testAJsonRequestAnswersTheCodedError(): void
    {
        $this->startWindow();

        $response = $this->getJson('/', ['Accept' => 'application/vnd.api+json']);

        $response->assertStatus(503);
        $this->assertOutageHeaders($response);
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $response->assertExactJson([
            'errors' => [
                [
                    'status' => '503',
                    'title' => 'Service Unavailable',
                    'code' => 'platform_maintenance',
                    'detail' => "We're doing some scheduled maintenance. Please try again in a minute or two.",
                ],
            ],
        ]);
    }

    public function testUpIsNotAnsweredByTheGate(): void
    {
        $this->startWindow();

        $this->get('/up')->assertOk();
    }

    public function testTheEndedWebhookReachesItsControllerAndClosesTheWindow(): void
    {
        $this->startWindow();
        $body = '{"event":"maintenance.ended","id":"window-1"}';

        $this->call('POST', '/webhooks/allsystems', [], [], [], $this->signedHeaders($body), $body)
            ->assertOk()
            ->assertExactJson(['action' => 'deactivated'])
        ;
        $this->get('/')->assertOk();
    }

    public function testAPostIsTurnedAway(): void
    {
        $this->startWindow();

        $this->post('/other')->assertStatus(503);
    }

    public function testAnExtraExceptPathPasses(): void
    {
        config(['allsystems.except' => ['up', 'status']]);
        $this->startWindow();

        $this->get('/status')->assertOk();
        $this->get('/')->assertStatus(503);
    }

    public function testWithNoWindowOpenNothingIsTurnedAwayAndNoQueryRuns(): void
    {
        config(['app.maintenance.store' => 'database']);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();
        $this->post('/other')->assertOk();
        self::assertSame([], $queries);
    }

    public function testAStartedWindowStill503sWithTheMaintenanceStoreSetToDatabase(): void
    {
        config(['app.maintenance.store' => 'database']);
        $this->startWindow();

        $this->get('/')->assertStatus(503);
    }

    public function testTheOutageResponseIsConfigurable(): void
    {
        $this->app?->make(ConfigRepository::class)->set([
            'allsystems.retry_after' => 120,
            'allsystems.json_detail' => 'Back soon.',
            'allsystems.view' => 'custom-outage',
        ]);
        View::addLocation(__DIR__ . '/views');
        $this->startWindow();

        $html = $this->get('/');
        $html->assertStatus(503);
        self::assertSame('120', $html->headers->get('Retry-After'));
        $html->assertSee('custom outage page', false);

        $this->getJson('/')->assertStatus(503)->assertJsonPath('errors.0.detail', 'Back soon.');
    }

    private function startWindow(): void
    {
        $this->app?->make(PlatformMaintenance::class)->start('window-1');
    }

    /** @param TestResponse<Response> $response */
    private function assertOutageHeaders(TestResponse $response): void
    {
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return array<string, string> */
    private function signedHeaders(string $body): array
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $v1 = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return $this->transformHeadersToServerVars([
            VerifySignature::HEADER => "t={$timestamp},v1={$v1}",
        ]);
    }
}
