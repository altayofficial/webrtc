<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec;

use FFI;
use FFI\Exception as FFIException;
use Webrtc\AVCodec\Exception\AvCodecException;

/**
 * Class SWScale
 *
 * This class provides methods to initialize the SWScale library and handle
 * SWScale-related operations.
 */
class SWScale
{
    /**
     * The path to the SWScale C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libav/include/swscale.h";

    /**
     * Initializes the SWScale library.
     *
     * This method attempts to load the libswscale shared library via FFI.
     * If initialization fails, a detailed exception is thrown.
     *
     * @return void
     *
     * @throws AvCodecException If the SWScale library cannot be loaded.
     */
    public static function init(): void
    {
        global $libSWScale;

        if (!isset($libSWScale)) {
            try {
                $lib = getenv("LIB_SWSCALE_PATH") ?: self::getLibPath();
                $libSWScale = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                if (!$libSWScale) {
                    throw new AvCodecException("FFI failed to load libswscale shared library.");
                }

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install FFmpeg (with development headers) from https://ffmpeg.org/download.html.
Ensure swscale-*.dll is accessible in your PATH or specify LIB_SWSCALE_PATH environment variable.
EOT,
                    'Darwin' => <<<EOT
Install FFmpeg with development headers on macOS:

    brew install ffmpeg

Make sure FFmpeg version is compatible and includes swscale.
EOT,
                    'Linux' => <<<EOT
Install FFmpeg development packages on Linux:

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libswscale-dev

For Fedora/RHEL:

    sudo dnf install ffmpeg-devel

Make sure installed version includes swscale library.
EOT,
                    default => "Please install FFmpeg and ensure libswscale is available. Visit https://ffmpeg.org/download.html for instructions."
                };

                throw new AvCodecException(sprintf(
                    "Couldn't load SWScale library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate libswscale shared library path.
     *
     * This method tries common library locations based on the operating system
     * and returns the most probable path to the libswscale shared library.
     *
     * @return string The path or name of the libswscale shared library.
     */
    private static function getLibPath(): string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'swscale-8.dll',
                'swscale-7.dll',
                'swscale.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libswscale.dylib',
                '/opt/homebrew/lib/libswscale.dylib',
                'libswscale.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libswscale.so',
                '/usr/local/lib/libswscale.so',
                'libswscale.so',
            ];
        } else {
            $candidates = [
                'libswscale',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'swscale.dll',
            'Darwin' => 'libswscale.dylib',
            'Linux' => 'libswscale.so',
            default => 'libswscale',
        };
    }
}
