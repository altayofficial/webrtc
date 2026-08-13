# SCTP Library for PHP

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A PHP library implementing SCTP (Stream Control Transmission Protocol) for WebRTC data channels. This package supports stream multiplexing, retransmissions, congestion control, and reliable or partially reliable data delivery.

## 📦 Features

- Full support for SCTP packet parsing and serialization
- Stream multiplexing for data channels
- Congestion control and retransmission mechanisms
- Timer-based delivery (RFC 3758)
- Partial reliability support

## Requirements

- PHP 8.4 or higher

## Documentation

This package is part of the PHP WebRTC library. For full documentation, examples, and API reference, visit:
[PHP WebRTC Documentation](https://www.quasarstream.com/php-webrtc)

## Credits

### Author

- **Amin Yazdanpanah** — [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
- **Sana Moniri** — [sanamoniri](https://github.com/sanamoniri)

## Reporting bugs

Please open an issue on GitHub if you encounter any problems.

## License

This project is licensed under the MIT License—see the LICENSE file.

## References

- [RFC 4960 – Stream Control Transmission Protocol](https://datatracker.ietf.org/doc/html/rfc4960)
- [RFC 8831 – WebRTC Data Channels](https://datatracker.ietf.org/doc/html/rfc8831)
- [RFC 3758 – SCTP Partial Reliability Extension](https://datatracker.ietf.org/doc/html/rfc3758)
