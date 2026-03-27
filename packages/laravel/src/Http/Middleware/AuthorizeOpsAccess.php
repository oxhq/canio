<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Oxhq\Canio\Contracts\OpsAccessAuthorizer;
use Oxhq\Canio\Support\OpsAccessConfiguration;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeOpsAccess
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Gate $gate,
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = OpsAccessConfiguration::resolve((array) config('canio.ops.access', []));

        if (! (bool) ($config['require_auth'] ?? false)) {
            return $next($request);
        }

        $user = $this->authenticatedUser((array) ($config['guards'] ?? []));

        if ($user !== null) {
            if (! $this->isAuthorized($request, $user, $config)) {
                abort(Response::HTTP_FORBIDDEN, 'This action is unauthorized.');
            }

            $request->attributes->set('canio.ops.access_method', 'session');

            return $next($request);
        }

        if ($this->passesBasicAuth($request, (array) ($config['basic'] ?? []))) {
            if (! $this->isAuthorized($request, null, $config, skipAbility: true)) {
                abort(Response::HTTP_FORBIDDEN, 'This action is unauthorized.');
            }

            $request->attributes->set('canio.ops.access_method', 'basic');

            return $next($request);
        }

        return $this->basicChallengeResponse((array) ($config['basic'] ?? []));
    }

    /**
     * @param  array<int, string>  $guards
     */
    private function authenticatedUser(array $guards): ?Authenticatable
    {
        $resolvedGuards = array_values(array_filter(array_map(
            static fn (mixed $guard): string => trim((string) $guard),
            $guards,
        )));

        if ($resolvedGuards === []) {
            $guard = $this->auth->guard();

            return $guard->check() ? $guard->user() : null;
        }

        foreach ($resolvedGuards as $guardName) {
            $guard = $this->auth->guard($guardName);

            if (! $guard->check()) {
                continue;
            }

            $this->auth->shouldUse($guardName);

            return $guard->user();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isAuthorized(Request $request, ?Authenticatable $user, array $config, bool $skipAbility = false): bool
    {
        if (! $skipAbility) {
            $ability = trim((string) ($config['ability'] ?? ''));

            if ($ability !== '' && ($user === null || ! $this->gate->forUser($user)->allows($ability))) {
                return false;
            }
        }

        $authorizer = trim((string) ($config['authorizer'] ?? ''));

        if ($authorizer === '') {
            return true;
        }

        $resolved = $this->container->make($authorizer);

        if (! $resolved instanceof OpsAccessAuthorizer) {
            throw new \RuntimeException(sprintf(
                'Configured Canio ops authorizer [%s] must implement %s.',
                $authorizer,
                OpsAccessAuthorizer::class,
            ));
        }

        return $resolved->authorize($request, $user);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function passesBasicAuth(Request $request, array $config): bool
    {
        if (! (bool) ($config['enabled'] ?? false)) {
            return false;
        }

        $expectedUsername = (string) ($config['username'] ?? '');
        $expectedPassword = (string) ($config['password'] ?? '');
        $providedUsername = (string) ($request->getUser() ?? '');
        $providedPassword = (string) ($request->getPassword() ?? '');

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        if ($providedUsername === '' && $providedPassword === '') {
            return false;
        }

        return hash_equals($expectedUsername, $providedUsername)
            && hash_equals($expectedPassword, $providedPassword);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function basicChallengeResponse(array $config): Response
    {
        if ((bool) ($config['enabled'] ?? false)) {
            return response('Unauthorized', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => sprintf('Basic realm="%s"', (string) ($config['realm'] ?? 'Canio Ops')),
            ]);
        }

        return response('Unauthorized', Response::HTTP_UNAUTHORIZED);
    }
}
