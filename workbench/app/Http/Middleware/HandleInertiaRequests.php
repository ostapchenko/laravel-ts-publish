<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $withAllErrors = true;

    /**
     * Define the props that are shared by default.
     *
     * @return array{
     *      auth: array{
     *          user: array{
     *              id: int,
     *              name: string,
     *              email: string
     *          }|null
     *      },
     *      flash: array{
     *          success: string|null,
     *          error: string|null
     *      },
     *      appName: string,
     *      filters?: array<string, string>
     *  }
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'appName' => config('app.name'),
            // Optional docblock key: the '?' rides on the parsed map key, so the override lookup has
            // to strip it or the prop is emitted twice (TS2300).
            'filters' => (array) $request->query('filters', []),
        ]);
    }
}
