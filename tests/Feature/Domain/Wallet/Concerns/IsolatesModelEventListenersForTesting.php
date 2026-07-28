<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Public-API-only event-listener isolation for tests that need to force a
 * specific, narrowly scoped Eloquent model-event outcome (a genuine
 * primary-key collision) without permanently modifying the shared model
 * event dispatcher.
 *
 * Never touches Illuminate\Events\Dispatcher's internal storage via
 * reflection. Instead it clones the dispatcher OBJECT itself, using only
 * documented public API: Model::getEventDispatcher() / setEventDispatcher()
 * (both public static, declared once on Illuminate\Database\Eloquent\Model
 * and shared by every model class - confirmed from source) and PHP's own
 * `clone` keyword. This is safe because Illuminate\Events\Dispatcher
 * defines no __clone() of its own (confirmed from its source): PHP's
 * default shallow clone gives the cloned dispatcher its own independent
 * $listeners array - arrays are copy-on-write value types in PHP, so
 * appending a listener to the clone's array never touches the original's -
 * while still sharing the same container/resolvers, which is desired, not
 * a concern.
 *
 * Deliberately does NOT use Dispatcher::getListeners(): that method wraps
 * every raw listener via makeListener() before returning it, and feeding
 * an already-wrapped closure back into listen() double-wraps it, breaking
 * on the next real dispatch. Cloning the dispatcher object sidesteps that
 * failure mode entirely - the clone's listeners are the exact same raw
 * closures as the original's, never read-and-rewrapped.
 */
trait IsolatesModelEventListenersForTesting
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function withIsolatedCreatingListener(string $modelClass, Closure $forcingListener, Closure $run): mixed
    {
        $eventName = 'eloquent.creating: '.$modelClass;
        $originalDispatcher = $modelClass::getEventDispatcher();

        if ($originalDispatcher === null) {
            throw new RuntimeException("No event dispatcher is registered for {$modelClass}.");
        }

        $isolatedDispatcher = clone $originalDispatcher;
        $isolatedDispatcher->listen($eventName, $forcingListener);

        $modelClass::setEventDispatcher($isolatedDispatcher);

        try {
            return $run();
        } finally {
            $modelClass::setEventDispatcher($originalDispatcher);
        }
    }
}
