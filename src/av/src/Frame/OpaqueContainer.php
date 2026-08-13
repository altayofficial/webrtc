<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Frame;

use FFI;
use FFI\CData;
use Ramsey\Uuid\Uuid;

/**
 * Opaque Container Class
 *
 * Provides a mechanism to store PHP objects and associate them with FFmpeg buffers,
 * allowing safe passing of PHP objects through FFmpeg's C API.
 *
 * @package Webrtc\AVCodec\Frame
 */
class OpaqueContainer
{
    /**
     * @var array $byName Storage for objects keyed by UUID
     */
    private array $byName;

    /**
     * Constructor
     *
     * @param FFI $libavcodec Initialized FFI instance for AVCodec
     */
    public function __construct(private readonly FFI $libavcodec)
    {
        $this->byName = [];
    }

    /**
     * Add an object to the container
     *
     * Creates an AVBufferRef containing a UUID that maps to the stored object
     *
     * @param mixed $v Object to store
     * @return CData FFI AVBufferRef containing the reference
     */
    public function add(mixed $v): CData
    {
        $uuid = Uuid::uuid4()->toString();
        $ref = $this->libavcodec->av_buffer_create($uuid, strlen($uuid), [$this, 'keyFree'], null, 0);
        $this->byName[$uuid] = $v;
        return $ref;
    }

    /**
     * Retrieve an object by name/UUID
     *
     * @param string $name UUID key of the object
     * @return mixed|null The stored object or null if not found
     */
    public function get(string $name): mixed
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Retrieve and remove an object by name/UUID
     *
     * @param string $name UUID key of the object
     * @return mixed|null The stored object or null if not found
     */
    public function pop(string $name): mixed
    {
        $value = $this->byName[$name] ?? null;
        unset($this->byName[$name]);
        return $value;
    }

    /**
     * Callback for buffer cleanup
     *
     * @param CData $opaque Opaque pointer from FFmpeg
     * @param CData $data Data pointer from FFmpeg
     */
    public function keyFree(FFI\CData $opaque, FFI\CData $data)
    {
        // TODO: Cleanup function if required
    }
}
