<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp;

use FFI;
use Webrtc\Srtp\Exception\SrtpException;
use FFI\Exception as FFIException;

/**
 * Class Srtp
 *
 * This class provides methods to initialize the SRTP library and handle
 * SRTP-related operations.
 */
class Srtp
{
    /**
     * The path to the SRTP C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libsrtp/include/srtp.h";

    /**
     * Initializes the SRTP library and returns an FFI instance.
     *
     * @return void
     *
     * @throws SrtpException if the SRTP library initialization fails.
     */
    public static function init(): void
    {
        global $libsrtp;

        if (!isset($libsrtp)) {
            try {
                $lib = getenv("LIB_SRTP_PATH") ?: self::getLibPath();
                $libsrtp = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                // Initialize SRTP library
                $srtpStatus = $libsrtp->srtp_init();
                if ($srtpStatus !== $libsrtp->srtp_err_status_ok) {
                    throw new SrtpException("Failed to initialize SRTP library. Status code: " . $srtpStatus);
                }

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and build libsrtp2 manually for Windows.
You can use vcpkg:

    vcpkg install libsrtp

Make sure the resulting DLLs are in your PATH or specify LIB_SRTP_PATH environment variable pointing to the .dll.
Official project: https://github.com/cisco/libsrtp
EOT,
                    'Darwin' => <<<EOT
Install libsrtp2 on macOS using Homebrew:

    brew install libsrtp

If you already have it but it fails to link, try:

    brew link --force libsrtp

Or reinstall:

    brew reinstall libsrtp

Official project: https://github.com/cisco/libsrtp
EOT,
                    'Linux' => <<<EOT
Install libsrtp2 development packages on Linux.

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libsrtp2-dev

For Fedora/RHEL:

    sudo dnf install libsrtp

If your distro does not have libsrtp2 or only an old version, you can build from source:
https://github.com/cisco/libsrtp
EOT,
                    default => "Please install libsrtp2 and ensure the development headers and shared library are available. See https://github.com/cisco/libsrtp for manual instructions."
                };

                throw new SrtpException(sprintf(
                    "Couldn't load SRTP library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate libsrtp shared library path.
     *
     * @return string
     */
    private static function getLibPath(): string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'libsrtp2-1.dll',
                'libsrtp2.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libsrtp2.dylib',
                '/opt/homebrew/lib/libsrtp2.dylib',
                'libsrtp2.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libsrtp2.so',
                '/usr/local/lib/libsrtp2.so',
                'libsrtp2.so',
            ];
        } else {
            $candidates = [
                'libsrtp2',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'libsrtp2.dll',
            'Darwin' => 'libsrtp2.dylib',
            'Linux' => 'libsrtp2.so',
            default => 'libsrtp2',
        };
    }
}
