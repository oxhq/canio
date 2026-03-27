<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\OpsAccessAuthorizer;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

it('rejects unauthenticated ops access when protection is required', function () {
    config()->set('canio.ops.access.require_auth', true);

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->get(route('canio.ops.index'))
        ->assertUnauthorized();
});

it('allows ops access through basic auth', function () {
    config()->set('canio.ops.access.require_auth', true);
    config()->set('canio.ops.access.basic.enabled', true);
    config()->set('canio.ops.access.basic.username', 'ops');
    config()->set('canio.ops.access.basic.password', 'secret-123');

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->withBasicAuth('ops', 'secret-123')
        ->get(route('canio.ops.index'))
        ->assertOk()
        ->assertSee('Canio Ops');
});

it('returns a basic auth challenge when ops basic auth is enabled', function () {
    config()->set('canio.ops.access.require_auth', true);
    config()->set('canio.ops.access.basic.enabled', true);
    config()->set('canio.ops.access.basic.username', 'ops');
    config()->set('canio.ops.access.basic.password', 'secret-123');
    config()->set('canio.ops.access.basic.realm', 'Ops Panel');

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->get(route('canio.ops.index'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Ops Panel"');
});

it('enforces the configured ops ability for authenticated users', function () {
    config()->set('canio.ops.access.require_auth', true);
    config()->set('canio.ops.access.ability', 'viewCanioOps');

    Gate::define('viewCanioOps', static fn (GenericUser $user): bool => false);

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Denied Operator']))
        ->get(route('canio.ops.index'))
        ->assertForbidden();
});

it('applies a custom ops authorizer when configured', function () {
    config()->set('canio.ops.access.require_auth', true);
    config()->set('canio.ops.access.authorizer', AllowHeaderOpsAuthorizer::class);

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->actingAs(new GenericUser(['id' => 2, 'name' => 'Operator']))
        ->get(route('canio.ops.index'))
        ->assertForbidden();

    $this->actingAs(new GenericUser(['id' => 2, 'name' => 'Operator']))
        ->withHeader('X-Canio-Ops-Key', 'allow')
        ->get(route('canio.ops.index'))
        ->assertOk();
});

it('applies the laravel-auth preset with the default canio ability', function () {
    config()->set('canio.ops.access.preset', 'laravel-auth');

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->get(route('canio.ops.index'))
        ->assertUnauthorized();

    Gate::define('viewCanioOps', static fn (GenericUser $user): bool => (int) $user->id === 99);

    $this->actingAs(new GenericUser(['id' => 12, 'name' => 'Viewer']))
        ->get(route('canio.ops.index'))
        ->assertForbidden();

    $this->actingAs(new GenericUser(['id' => 99, 'name' => 'Operator']))
        ->get(route('canio.ops.index'))
        ->assertOk();
});

it('applies the basic-auth preset with a browser challenge', function () {
    config()->set('canio.ops.access.preset', 'basic-auth');
    config()->set('canio.ops.access.basic.username', 'ops');
    config()->set('canio.ops.access.basic.password', 'secret-123');
    config()->set('canio.ops.access.basic.realm', 'Canio Protected Ops');

    swapOpsAccessClient(new OpsAccessFakeClient);

    $this->get(route('canio.ops.index'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Canio Protected Ops"');

    $this->withBasicAuth('ops', 'secret-123')
        ->get(route('canio.ops.index'))
        ->assertOk();
});

function swapOpsAccessClient(StagehandClient $client): void
{
    app()->instance(StagehandClient::class, $client);
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);
}

final class AllowHeaderOpsAuthorizer implements OpsAccessAuthorizer
{
    public function authorize(Request $request, ?Authenticatable $user): bool
    {
        return $request->header('X-Canio-Ops-Key') === 'allow';
    }
}

final class OpsAccessFakeClient implements StagehandClient
{
    public function render(RenderSpec $spec): RenderResult
    {
        throw new RuntimeException('not used');
    }

    public function dispatch(RenderSpec $spec): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function job(string $jobId): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function jobs(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.jobs.v1',
            'count' => 0,
            'items' => [],
        ];
    }

    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        return [];
    }

    public function cancelJob(string $jobId): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function artifact(string $artifactId): array
    {
        throw new RuntimeException('not used');
    }

    public function artifacts(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.artifacts.v1',
            'count' => 0,
            'items' => [],
        ];
    }

    public function deadLetters(): array
    {
        return [
            'contractVersion' => 'canio.stagehand.dead-letters.v1',
            'count' => 0,
            'items' => [],
        ];
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function cleanupDeadLetters(?int $olderThanDays = null): array
    {
        return [];
    }

    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
    {
        return [];
    }

    public function replay(string $artifactId): RenderResult
    {
        throw new RuntimeException('not used');
    }

    public function status(): array
    {
        return [
            'contractVersion' => 'canio.stagehand.runtime-status.v1',
            'runtime' => [
                'state' => 'ready',
            ],
            'queue' => [
                'depth' => 0,
            ],
            'browserPool' => [
                'size' => 1,
                'busy' => 0,
                'warm' => 1,
            ],
            'workerPool' => [
                'size' => 1,
                'busy' => 0,
                'warm' => 1,
            ],
        ];
    }

    public function restart(): array
    {
        return $this->status();
    }
}
