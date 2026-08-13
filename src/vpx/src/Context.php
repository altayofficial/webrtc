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
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\VPX\Exception\VpxException;

/**
 * Context class for VPX codec.
 *
 * This class provides methods to manage the VPX codec context.
 * It utilizes the Foreign Function Interface (FFI) to interact with the VPX shared library.
 *
 * @deprecated
 */
class Context implements SharedLibraryInterface
{
    private FFI $libVpx;
    private ?FFI\CData $context;

    /**
     * Context constructor.
     *
     * Initializes the context and allocates the necessary resources.
     *
     * @throws VpxException If the context allocation fails.
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();

        $this->context = $this->libVpx->new("vpx_codec_ctx_t");

        if ($this->context == NULL) {
            throw new VpxException("Failed to allocate context.");
        }
    }

    /**
     * Get the shared library instance.
     *
     * Sets the libVpx property if the global libVpx instance is available.
     *
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libVpx;

        if ($libVpx instanceof FFI) {
            $this->libVpx = $libVpx;
        }
    }

    public function __destruct()
    {
        $this->libVpx->vpx_codec_destroy(FFI::addr($this->context));
    }

    /**
     * Get the current context.
     *
     * @return FFI\CData|null The current codec context.
     */
    public function getCtx(): ?FFI\CData
    {
        return $this->context;
    }
}
