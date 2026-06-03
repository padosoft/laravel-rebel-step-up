<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\StepUp\RebelStepUp;
use Padosoft\Rebel\StepUp\StepUpContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects a route by requiring step-up for a given purpose.
 *
 *   Route::post('/account/change-email', ChangeEmailController::class)
 *       ->middleware('rebel.stepup:change-email');
 *
 * If no valid confirmation exists: 423 (JSON) with the available drivers, or a redirect
 * to the step-up screen (config `redirect_route`).
 */
final class EnsureStepUp
{
    public function __construct(
        private readonly RebelStepUp $stepUp,
        private readonly KeyedHasher $hasher,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $context = new StepUpContext(
            $user,
            $purpose,
            SecurityContext::fromRequest($request, $this->hasher)->withPurpose($purpose),
        );

        if ($this->stepUp->isConfirmed($context)) {
            return $next($request);
        }

        $drivers = array_map(
            static fn ($driver) => $driver->key(),
            $this->stepUp->availableDrivers($context),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'step_up_required',
                'purpose' => $purpose,
                'required_assurance' => $this->stepUp->policy($purpose)->requiredAssurance->value,
                'drivers' => $drivers,
            ], Response::HTTP_LOCKED);
        }

        $redirect = config('rebel-step-up.redirect_route');

        if (is_string($redirect)) {
            return redirect()->route($redirect, ['purpose' => $purpose]);
        }

        abort(Response::HTTP_LOCKED);
    }
}
