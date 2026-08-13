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
 * Class AVFilter
 *
 * This class provides methods to initialize the AVFilter library and handle
 * AVFilter-related operations.
 */
class AVFilter
{
    /**
     * The minimum supported major version of libavfilter.
     * @var int
     */
    private const SUPPORTED_VERSION = 7;

    /**
     * The path to the AVFilter C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libav/include/avfilter.h";

    /**
     * Initializes the AVFilter library.
     *
     * This method attempts to load the libavfilter shared library via FFI, verify its version,
     * and set necessary configurations. If initialization fails, a detailed exception is thrown.
     *
     * @param bool $debug Whether to enable debug logging for AVFilter.
     *
     * @return void
     *
     * @throws AvCodecException If the AVFilter library cannot be loaded or the version is unsupported.
     */
    public static function init(bool $debug = false): void
    {
        global $libAVFilter;

        if (!isset($libAVFilter)) {
            try {
                $lib = getenv("LIB_AVFILTER_PATH") ?: self::getLibPath();
                $libAVFilter = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                // Verify the loaded library version
                $version = $libAVFilter->av_version_info();

                if ($version < self::SUPPORTED_VERSION) {
                    throw new AvCodecException(sprintf(
                        "The library could not be initialized. Required version: %d or higher. Detected version: %d.",
                        self::SUPPORTED_VERSION,
                        $version
                    ));
                }

                if ($debug) {
                    $logCallback = function ($avcl, $level, $fmt, $args) {
                        echo "[FFmpeg] $fmt\n";
                    };
                    $libAVFilter->av_log_set_callback($logCallback);
                    $libAVFilter->av_log(null, 8, "Test log message\n");
                }

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install FFmpeg (version 7.x or higher) with development files (DLLs) from https://ffmpeg.org/download.html.
Make sure avfilter-*.dll is in your PATH or specify the correct LIB_AVFILTER_PATH environment variable.
EOT,
                    'Darwin' => <<<EOT
Install FFmpeg with development headers on macOS:

    brew install ffmpeg

Ensure it is version 7.x or higher. You may need to run:

    brew upgrade ffmpeg
EOT,
                    'Linux' => <<<EOT
Install FFmpeg development packages on Linux:

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libavfilter-dev

For Fedora/RHEL:

    sudo dnf install ffmpeg-devel

Ensure the installed version is 7.x or higher. If needed, build FFmpeg manually from source.
EOT,
                    default => "Please install FFmpeg (version 7.x or higher) and ensure development libraries are available. See https://ffmpeg.org/download.html for instructions."
                };

                throw new AvCodecException(sprintf(
                    "Couldn't load AvFilter library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate libavfilter shared library path.
     *
     * This method tries common library locations based on the operating system
     * and returns the most probable path to the libavfilter shared library.
     * If no common path is found, it returns a generic library name.
     *
     * @return string The path or name of the libavfilter shared library.
     */
    private static function getLibPath(): string
    {
        $candidates = [];

        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'avfilter-8.dll',
                'avfilter-7.dll',
                'avfilter.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libavfilter.dylib',
                '/opt/homebrew/lib/libavfilter.dylib',
                'libavfilter.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libavfilter.so',
                '/usr/local/lib/libavfilter.so',
                'libavfilter.so',
            ];
        } else {
            $candidates = [
                'libavfilter',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'avfilter.dll',
            'Darwin' => 'libavfilter.dylib',
            'Linux' => 'libavfilter.so',
            default => 'libavfilter',
        };
    }
}
