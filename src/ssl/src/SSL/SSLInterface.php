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

use Webrtc\Exception\RuntimeException;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;

interface SSLInterface
{
    public function setAcceptState(): void;

    public function setConnectState(): void;

    public function doHandshake(): void;

    public function getPeerCertificateDigest(): ?string;

    public function getSelectedSrtpProfile(): string;

    public function exportKeyingMaterial(string $label, int $keyLength, ?string $context = null): string;

    public function shutdown(): bool ;

    public function dtlsV1GetTimeout(): ?float ;

    public function dtlsV1HandleTimeout(): bool ;

    public function read(int $bufsiz, ?int $flags = null): string;

    public function write(string $buf, int $flags = 0): void;
}