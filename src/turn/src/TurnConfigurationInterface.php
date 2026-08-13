<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN;

interface TurnConfigurationInterface
{
    public function getTurnServer(): array|null;

    public function setTurnServer(array $turnServer): void;

    public function getTurnSsl(): bool;

    public function setTurnSsl(bool $turnSsl): void;

    public function getTurnUsername(): string|null;

    public function setTurnUsername(string $turnUsername): void;

    public function getTurnPassword(): string|null;

    public function setTurnPassword(string $turnPassword): void;

    public function getTurnTransport(): string;

    public function setTurnTransport(string $turnTransport): void;
}