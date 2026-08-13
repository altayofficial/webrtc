<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\VPX;

use FFI;
use FFI\Exception as FFIException;
use Webrtc\VPX\Exception\VpxException;

/**
 * Class Vpx
 *
 * This class provides methods to initialize the VPX (libvpx) library and handle VP8/VP9 codec operations.
 *
 * @deprecated
 */
class Vpx
{
    /**
     * Required minimum supported VPX codec version.
     * @var int
     */
    private const SUPPORTED_VERSION = 69375;

    /**
     * The path to the VPX C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libvpx/include/vpx.h";

    /**
     * Initializes the VPX library and returns an FFI instance.
     *
     * @throws VpxException if the VPX library initialization fails.
     */
    public static function init(): void
    {
        global $libVpx;

        if (!isset($libVpx)) {
            try {
                $lib = getenv("LIBVPX_PATH") ?: self::getLibPath();
                $libVpx = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                if (!$libVpx) {
                    throw new VpxException("FFI failed to load VPX shared library.");
                }

                // Verify the library version
                $version = $libVpx->vpx_codec_version();
                if ($version === self::SUPPORTED_VERSION) {
                    throw new VpxException(sprintf(
                        "The library could not be initialized. Required version is %d or higher, detected version is %d.",
                        self::SUPPORTED_VERSION,
                        $version
                    ));
                }

                self::setDefinition();

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install libvpx for Windows manually or using MSYS2:

Using MSYS2:

    pacman -S mingw-w64-x86_64-libvpx

Or download prebuilt binaries from trusted sources.
Make sure vpx-*.dll is available in your PATH or specify the LIBVPX_PATH environment variable.
EOT,
                    'Darwin' => <<<EOT
Install libvpx on macOS using Homebrew:

    brew install libvpx

If you already have it installed but not linked:

    brew link libvpx --force

EOT,
                    'Linux' => <<<EOT
Install libvpx development packages on Linux.

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libvpx-dev

For Fedora/RHEL:

    sudo dnf install libvpx-devel

If your distribution provides an outdated version, consider building libvpx manually from:

    https://chromium.googlesource.com/webm/libvpx
EOT,
                    default => "Please install libvpx (VP8/VP9 codec library) with development headers and shared libraries available. See https://chromium.googlesource.com/webm/libvpx/ for source instructions."
                };

                throw new VpxException(sprintf(
                    "Couldn't load VPX library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate libvpx shared library path.
     *
     * @return string
     */
    private static function getLibPath(): string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'vpx.dll',
                'libvpx-1.dll',
                'libvpx.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libvpx.dylib',
                '/opt/homebrew/lib/libvpx.dylib',
                'libvpx.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/local/lib/libvpx.so',
                '/usr/lib/x86_64-linux-gnu/libvpx.so',
                'libvpx.so',
            ];
        } else {
            $candidates = [
                'libvpx',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'libvpx.dll',
            'Darwin' => 'libvpx.dylib',
            'Linux' => 'libvpx.so',
            default => 'libvpx',
        };
    }

    /**
     * Defines VPX-related constants manually.
     *
     * @return void
     */
    private static function setDefinition(): void
    {
        define("PACKET_MAX", 1300);
        define('VPX_ENCODER_ABI_VERSION', 36); // Should match your actual vpx.h
        define('VPX_DECODER_ABI_VERSION', 12);
        define("VPX_DL_REALTIME", 1);
    }
}
