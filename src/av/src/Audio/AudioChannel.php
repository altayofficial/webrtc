<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Audio;

/**
 * Class AudioChannel
 *
 * Represents an audio channel with a name and description.
 */
class AudioChannel
{
    /**
     * AudioChannel constructor.
     *
     * @param string $name The name of the audio channel.
     * @param string $description A brief description of the audio channel.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description
    )
    {
    }

    /**
     * @return string The name of the audio channel.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string The description of the audio channel.
     */
    public function getDescription(): string
    {
        return $this->description;
    }
}
