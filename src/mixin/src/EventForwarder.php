<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Mixin;

use Evenement\EventEmitterInterface;

/**
 * Trait EventForwarder
 *
 * Provides utility methods for forwarding events from an event emitter to either
 * methods on the current object or re-emitting them from the current object.
 *
 * This trait assumes the using class implements `EventEmitterInterface` or has
 * a compatible `emit()` method.
 */
trait EventForwarder
{
    /**
     * Forwards specific events from the given source to corresponding methods on the current object.
     *
     * Each event is mapped to a method name, and when the event is emitted from the source,
     * the corresponding method on the current object is called with the event's arguments.
     *
     * @param EventEmitterInterface $source The event emitter source to listen to.
     * @param array<string, string> $events An associative array where keys are event names and values are method names to invoke.
     *
     * @return array<callable> The list of registered event listener callbacks, which can be used for removal later if needed.
     */
    private function forwardEvents2Methods(EventEmitterInterface $source, array $events): array
    {
        $callbacks = [];
        foreach ($events as $event => $method) {
            $callbacks[] = $callback = fn(...$args) => $this->{$method}(...$args);
            $source->on($event, $callback);
        }

        return $callbacks;
    }

    /**
     * Forwards events from the given source by re-emitting them from the current object.
     *
     * This method listens to the given events from the source and re-emits them as-is using the
     * current object's `emit()` method with the same event name and arguments.
     *
     * @param EventEmitterInterface $source The event emitter source to listen to.
     * @param array<int, string> $events A list of event names to forward.
     *
     * @return void
     */
    private function forwardEvents(EventEmitterInterface $source, array $events): void
    {
        foreach ($events as $event) {
            $source->on($event, function () use ($event) {
                $this->emit($event, \func_get_args());
            });
        }
    }
}
