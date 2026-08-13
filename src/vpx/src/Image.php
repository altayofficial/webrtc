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
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\VPX\Enum\ImageFormat;
use Webrtc\VPX\Exception\VpxException;

/**
 * Image represents a VPX image buffer for video encoding/decoding operations.
 *
 * This class handles the allocation and management of VPX image structures (vpx_image_t)
 * used in video processing pipelines. It implements SharedLibraryInterface to work
 * with libvpx through FFI and provides methods for manipulating image data.
 *
 * @deprecated
 */
class Image implements SharedLibraryInterface
{
    /** @var FFI Reference to the loaded libvpx shared library */
    private FFI $libVpx;

    /** @var FFI\CData|null The underlying vpx_image_t structure */
    private ?FFI\CData $image;

    /**
     * Creates a new Image instance with specified dimensions and format.
     *
     * @param int $width The width of the image in pixels
     * @param int $height The height of the image in pixels
     * @param ImageFormat $imageFormat The pixel format of the image
     * @throws VpxException If image allocation fails
     */
    public function __construct(private readonly int $width, private readonly int $height, ImageFormat $imageFormat)
    {
        $this->initiateSharedLibrary();

        $this->image = $this->libVpx->new("vpx_image_t");
        $imgAlloc = $this->libVpx->vpx_img_alloc(FFI::addr($this->image), $this->libVpx->{$imageFormat->value}, $width, $height, 1);

        if ($imgAlloc === NULL) {
            throw new VpxException("Failed to allocate image.");
        }
    }

    /**
     * Copies video frame data into the image buffer.
     *
     * This method populates the image planes and stride information from
     * a VideoFrame object. It handles YUV planar data (typically 3 planes).
     *
     * @param VideoFrame $frame The source video frame to copy
     */
    public function putData(VideoFrame $frame): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->image->planes[$i] = $frame->getFrame()->data[$i];
            $this->image->stride[$i] = $frame->getFrame()->linesize[$i];
        }
    }

    /**
     * Initializes the shared library reference from the global scope.
     *
     * Implements the SharedLibraryInterface requirement to connect the
     * class instance with the loaded FFI library instance.
     */
    public function initiateSharedLibrary(): void
    {
        global $libVpx;

        if ($libVpx instanceof FFI) {
            $this->libVpx = $libVpx;
        }
    }

    /**
     * Gets the width of the image.
     *
     * @return int The image width in pixels
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Gets the height of the image.
     *
     * @return int The image height in pixels
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Gets the underlying vpx_image_t structure.
     *
     * @return FFI\CData|null The FFI CData structure representing the image
     */
    public function getImage(): ?FFI\CData
    {
        return $this->image;
    }

    /**
     * Destructor - ensures proper cleanup of image resources.
     */
    public function __destruct()
    {
        $this->libVpx->vpx_img_free(FFI::addr($this->image));
    }
}