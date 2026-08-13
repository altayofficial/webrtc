<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\SSL;

use FFI\CData;

interface BIOInterface
{
    public function read(): ?string;

    public function getPendingBytes(): int;

    public function write(string $buf): int ;

    public function handleBioErrors(CData $bio) ;

    public function setBufferSize(int $bufferSize): void;
}