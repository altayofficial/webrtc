<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\Crypto;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use FFI;
use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Exception\OpenSSLException;

/**
 * X.509 Certificate Handler
 *
 * Provides comprehensive functionality for creating, managing, and inspecting X.509 certificates
 * using OpenSSL's X509 functions through FFI. Implements SharedLibraryInterface for FFI library management.
 *
 * @package Webrtc\SSL\Crypto
 */
class X509 implements SharedLibraryInterface
{
    /** @var int Default X.509 version (v3) */
    private const X509Version = 2;

    /** @var FFI Reference to the OpenSSL FFI library */
    private FFI $libssl;

    /** @var CData FFI CData structure representing the X509 certificate */
    private CData $x509;

    /** @var CData FFI CData structure representing the subject name */
    private CData $subjectName;

    /**
     * X509 constructor
     *
     * Initializes a new X509 certificate structure or wraps an existing one
     *
     * @param CData|null $x509 Optional existing X509 structure to wrap
     * @throws OpenSSLException If the X509 structure cannot be created
     */
    public function __construct(?CData $x509 = null)
    {
        $this->initiateSharedLibrary();
        $this->x509 = $x509 ?? $this->libssl->X509_new();

        if (FFI::isNull($this->x509)) {
            throw new OpenSSLException("Failed to create X509 structure");
        }
        $this->setVersion(self::X509Version);
    }

    /**
     * Sets the version number of the X.509 certificate
     *
     * @param int $version The version number (2 = v3)
     */
    public function setVersion(int $version): void
    {
        $this->libssl->X509_set_version($this->x509, $version);
    }

    /**
     * Gets the version number of the X.509 certificate
     *
     * @return int The version number
     */
    public function getVersion(): int
    {
        return $this->libssl->X509_get_version($this->x509);
    }

    /**
     * Sets a default serial number (1) for the certificate
     */
    public function setSerialNumberDefualt(): void
    {
        $serial = $this->libssl->X509_get_serialNumber($this->x509);
        $this->libssl->ASN1_INTEGER_set($serial, 1);
    }

    /**
     * Sets a custom serial number for the certificate
     *
     * @param string $serial The serial number as a decimal string
     * @throws OpenSSLException If the serial number cannot be set
     */
    public function setSerialNumber(string $serial): void
    {
        $bn = $this->libssl->BN_new();
        if ($bn === null) {
            throw new OpenSSLException("Failed to create BIGNUM");
        }

        if ($this->libssl->BN_dec2bn(FFI::addr($bn), $serial) === 0) {
            $this->libssl->BN_free($bn);
            throw new OpenSSLException("Failed to convert serial number to BIGNUM");
        }

        $asn1Int = $this->libssl->BN_to_ASN1_INTEGER($bn, null);
        if ($asn1Int === null) {
            $this->libssl->BN_free($bn);
            throw new OpenSSLException("Failed to convert BIGNUM to ASN1_INTEGER");
        }

        if ($this->libssl->X509_set_serialNumber($this->x509, $asn1Int) !== 1) {
            $this->libssl->BN_free($bn);
            throw new OpenSSLException("Failed to set serial number in X509");
        }

        $this->libssl->BN_free($bn);
    }

    /**
     * Gets the serial number of the certificate
     *
     * @return string The serial number as a decimal string
     * @throws OpenSSLException If the serial number cannot be retrieved
     */
    public function getSerialNumber(): string
    {
        $asn1Int = $this->libssl->X509_get_serialNumber($this->x509);
        if ($asn1Int === null) {
            throw new OpenSSLException("Failed to get serial number");
        }

        $bn = $this->libssl->ASN1_INTEGER_to_BN($asn1Int, null);
        if ($bn === null) {
            throw new OpenSSLException("Failed to convert ASN1_INTEGER to BIGNUM");
        }

        $dec = $this->libssl->BN_bn2dec($bn);
        $serial = FFI::string($dec);

        // Free allocated memory
        $this->libssl->BN_free($bn);
        $this->libssl->CRYPTO_free($dec, null, 0);

        return $serial;
    }

    /**
     * Sets the certificate's validity start date
     *
     * @param DateTimeImmutable $dateTime The not-before date
     */
    public function setDateNotBefore(DateTimeImmutable $dateTime): void
    {
        $this->setDate("Before", $dateTime);
    }

    /**
     * Sets the certificate's validity end date
     *
     * @param DateTimeImmutable $dateTime The not-after date
     */
    public function setDateNotAfter(DateTimeImmutable $dateTime): void
    {
        $this->setDate("After", $dateTime);
    }

    /**
     * Internal method to set certificate dates
     *
     * @param string $when "Before" or "After"
     * @param DateTimeImmutable $dateTime The date to set
     */
    private function setDate(string $when, DateTimeImmutable $dateTime): void
    {
        $this->libssl->X509_gmtime_adj($this->libssl->{"X509_getm_not" . $when}($this->x509), $this->calcDiffInSeconds($dateTime));
    }

    /**
     * Gets the certificate's validity start date
     *
     * @return DateTimeImmutable|null The not-before date or null if not set
     * @throws Exception
     */
    public function getDateNotBefore(): ?DateTimeImmutable
    {
        return $this->getDate("Before");
    }

    /**
     * Gets the certificate's validity end date
     *
     * @return DateTimeImmutable|null The not-after date or null if not set
     * @throws Exception
     */
    public function getDateNotAfter(): ?DateTimeImmutable
    {
        return $this->getDate("After");
    }

    /**
     * Internal method to get certificate dates
     *
     * @param string $when "Before" or "After"
     * @return DateTimeImmutable|null The date or null if not set
     * @throws Exception If date conversion fails
     */
    private function getDate(string $when): ?DateTimeImmutable
    {
        $asn1Time = $this->libssl->{"X509_getm_not" . $when}($this->x509);
        if ($asn1Time === null) {
            return null;
        }

        $asn1String = $this->libssl->cast("ASN1_STRING*", $asn1Time);

        if ($asn1String === null) {
            throw new Exception("ASN1_STRING is null, invalid data");
        }

        if ($this->libssl->ASN1_STRING_length($asn1String) == 0) {
            return null;
        }

        if ($this->libssl->ASN1_STRING_type($asn1String) == 24) {
            $dataPointer = $this->libssl->ASN1_STRING_get0_data($asn1String);

            if ($dataPointer === null) {
                throw new Exception("Failed to retrieve valid data pointer from ASN1_STRING");
            }
            $length = $this->libssl->ASN1_STRING_length($asn1String);

            return DateTimeImmutable::createFromFormat('YmdHis\Z', FFI::string($dataPointer, $length), new DateTimeZone('UTC'));
        } else {
            $generalizedTimestamp = $this->libssl->new("ASN1_GENERALIZEDTIME*");
            $this->libssl->ASN1_TIME_to_generalizedtime($asn1Time, FFI::addr($generalizedTimestamp));

            if ($generalizedTimestamp[0] === null) {
                throw new Exception("Generalized time conversion failed, null pointer returned");
            }

            $asn1String = $this->libssl->cast("ASN1_STRING*", $generalizedTimestamp);
            if ($asn1String === null) {
                throw new Exception("ASN1_STRING cast failed after generalized time conversion");
            }

            $stringData = $this->libssl->ASN1_STRING_get0_data($asn1String);
            if ($stringData === null) {
                throw new Exception("Failed to retrieve data from ASN1_STRING after casting");
            }
            $length = $this->libssl->ASN1_STRING_length($asn1String);

            $datetimeStr =  FFI::string($stringData, $length);
            return DateTimeImmutable::createFromFormat('YmdHis\Z', $datetimeStr, new DateTimeZone('UTC'));
        }
    }

    /**
     * Sets the public key for the certificate
     *
     * @param PrivateKeyInterface $pkey The private key containing the public key
     * @throws OpenSSLException If the public key cannot be set
     */
    public function setPublicKey(PrivateKeyInterface $pkey): void
    {
        $ret = $this->libssl->X509_set_pubkey($this->x509, $pkey->getPKey()->getPkey());
        if ($ret !== 1) {
            throw new OpenSSLException("Failed to set public key in X509");
        }
    }

    /**
     * Gets the public key from the certificate
     *
     * @return mixed The EVP_PKEY structure containing the public key
     * @throws OpenSSLException If the public key cannot be retrieved
     */
    public function getPublicKey(): mixed
    {
        $pkey = $this->libssl->X509_get_pubkey($this->x509);
        if ($pkey === null) {
            throw new OpenSSLException("Failed to get public key from X509 certificate");
        }

        return $pkey;
    }

    /**
     * Gets the subject name structure
     *
     * @return CData The X509_NAME structure containing subject details
     */
    public function getSubjectName(): CData
    {
        return $this->subjectName;
    }

    /**
     * Initializes the subject name structure
     */
    public function setSubjectName(): void
    {
        $this->subjectName = $this->libssl->X509_get_subject_name($this->x509);
    }

    /**
     * Adds a subject field to the certificate
     *
     * @param string $fieldType The field type (e.g., "commonName")
     * @param string $fieldValue The field value
     * @throws Exception If the field type is invalid
     */
    public function addSubjectField(string $fieldType, string $fieldValue): void
    {
        $nid = $this->libssl->OBJ_ln2nid($fieldType);
        if ($nid === 0) {
            throw new Exception("Invalid field type: $fieldType");
        }

        $subjectName = $this->libssl->X509_get_subject_name($this->x509);
        $this->libssl->X509_NAME_add_entry_by_NID($subjectName, $nid, 0, FFI::new("unsigned char[128]"), strlen($fieldValue), -1, 0);
        $this->libssl->X509_set_subject_name($this->x509, $subjectName);
    }

    /**
     * Adds an entry to the certificate's subject name
     *
     * @param string $field The field name
     * @param string $value The field value
     * @throws OpenSSLException If the entry cannot be added
     */
    public function addEntry(string $field, string $value): void
    {
        $length = strlen($value);
        $buffer = $this->libssl->new("unsigned char[" . ($length + 1) . "]");
        for ($i = 0; $i < $length; $i++) {
            $buffer[$i] = ord($value[$i]);
        }
        $buffer[$length] = 0; // Null terminator
        $ret = $this->libssl->X509_NAME_add_entry_by_txt(
            $this->getSubjectName(),
            $field,
            0x1000,
            $this->libssl->cast("const unsigned char*", $buffer),
            $length,
            -1,
            0
        );
        if ($ret !== 1) {
            throw new OpenSSLException("Failed to add entry: $field");
        }
    }

    /**
     * Sets the issuer name (copies subject name by default)
     *
     * @throws OpenSSLException If the issuer name cannot be set
     */
    public function setIssuerName(): void
    {
        $ret = $this->libssl->X509_set_issuer_name($this->x509, $this->subjectName);
        if ($ret !== 1) {
            throw new OpenSSLException("Failed to set issuer name");
        }
    }

    /**
     * Signs the certificate with a private key
     *
     * @param CData|PrivateKeyInterface $pkey The signing key
     * @throws OpenSSLException If signing fails
     */
    public function sign(CData|PrivateKeyInterface $pkey): void
    {
        $pkey = $pkey instanceof CData ? $pkey : $pkey->getPKey()->getPkey();
        $ret = $this->libssl->X509_sign($this->x509, $pkey, $this->libssl->EVP_sha256());
        if ($ret === 0) {
            throw new OpenSSLException("Failed to sign X509 certificate");
        }
    }

    /**
     * Gets the certificate expiration date
     *
     * @return DateTimeImmutable The not-after date
     * @throws OpenSSLException If date conversion fails
     * @throws DateMalformedStringException
     */
    public function getExpires(): DateTimeImmutable
    {
        $notAfter = $this->libssl->X509_getm_notAfter($this->x509);

        // Convert ASN1_TIME to struct tm
        $tm = $this->libssl->new("struct tm");
        $ret = $this->libssl->ASN1_TIME_to_tm($notAfter, FFI::addr($tm));
        if ($ret === 0) {
            throw new OpenSSLException("Failed to convert ASN1_TIME to struct tm");
        }

        $dateString = sprintf('%04d-%02d-%02d %02d:%02d:%02d',
            $tm->tm_year + 1900,
            $tm->tm_mon + 1,
            $tm->tm_mday,
            $tm->tm_hour,
            $tm->tm_min,
            $tm->tm_sec
        );
        return new DateTimeImmutable($dateString, new DateTimeZone('UTC'));
    }

    /**
     * Gets the certificate fingerprint
     *
     * @param string $digestName The hash algorithm (e.g., "sha1")
     * @return string The fingerprint as a colon-separated hex string
     * @throws OpenSSLException If the digest fails
     */
    public function getDigits(string $digestName): string
    {
        $digest = $this->libssl->EVP_get_digestbyname($digestName);
        if ($digest == null) {
            throw new OpenSSLException("No such digest method");
        }

        $resultBuffer = $this->libssl->new("unsigned char[" . EVP_MAX_MD_SIZE . "]");
        $resultLength = $this->libssl->new("unsigned int[1]");
        $resultLength[0] = EVP_MAX_MD_SIZE;

        $digestResult = $this->libssl->X509_digest($this->x509, $digest, $resultBuffer, $resultLength);
        if ($digestResult != 1) {
            throw new OpenSSLException("Digest calculation failed");
        }

        $encodedResult = [];
        for ($i = 0; $i < $resultLength[0]; $i++) {
            $encodedResult[] = strtoupper(bin2hex(chr($resultBuffer[$i])));
        }

        return implode(":", $encodedResult);
    }

    /**
     * Loads an X.509 certificate from a file
     *
     * @param string $certFilePath Path to the certificate file
     * @return static The loaded certificate
     * @throws OpenSSLException If loading fails
     */
    public static function loadFile(string $certFilePath): static
    {
        global $libssl;

        if (!$libssl instanceof FFI) {
            throw new OpenSSLException("Couldn't load libssl library");
        }

        $file = $libssl->fopen($certFilePath, "r");

        if ($file === null) {
            throw new OpenSSLException("Failed to open certificate file");
        }

        $x509 = $libssl->PEM_read_X509($file, null, null, null);

        $libssl->fclose($file);

        if ($x509 === null) {
            throw new OpenSSLException("Failed to load certificate from file");
        }

        return new static($x509);
    }

    /**
     * Initializes the shared OpenSSL library reference
     */
    public function initiateSharedLibrary(): void
    {
        global $libssl;

        if ($libssl instanceof FFI) {
            $this->libssl = $libssl;
        }
    }

    /**
     * Destructor - frees the X509 structure
     */
    public function __destruct()
    {
        $this->libssl->X509_free($this->x509);
    }

    /**
     * Calculates time difference in seconds for date adjustments
     *
     * @param DateTimeImmutable $dateTime The target date
     * @return int Difference in seconds
     */
    private function calcDiffInSeconds(DateTimeImmutable $dateTime): int
    {
        $now = new DateTimeImmutable();
        return $dateTime->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Gets the underlying X509 structure
     *
     * @return CData The X509 structure
     */
    public function getX509(): CData
    {
        return $this->x509;
    }

    /**
     * Gets a certificate extension by index
     *
     * @param int $index The extension index
     * @return string The extension data
     * @throws RuntimeException If the extension cannot be retrieved
     */
    public function getExtension(int $index): string
    {
        $extension = $this->libssl->X509_get_ext($this->x509, $index);
        if ($extension === null) {
            throw new RuntimeException("Failed to retrieve extension at index $index.");
        }

        $extensionData = $this->libssl->X509_EXTENSION_get_data($extension);
        if ($extensionData === null) {
            throw new RuntimeException("No data found for extension at index $index.");
        }

        return FFI::string($extensionData);
    }

    /**
     * Gets all subject fields from the certificate
     *
     * @return array Associative array of subject fields
     */
    public function getSubject(): array
    {
        $subjectName = $this->libssl->X509_get_subject_name($this->x509);
        $subjectCount = $this->libssl->X509_NAME_entry_count($subjectName);
        $subjectDetails = [];

        for ($i = 0; $i < $subjectCount; $i++) {
            $entry = $this->libssl->X509_NAME_get_entry($subjectName, $i);
            $obj = $this->libssl->X509_NAME_ENTRY_get_object($entry);
            $data = $this->libssl->X509_NAME_ENTRY_get_data($entry);
            $length = $this->libssl->ASN1_STRING_length($data);
            $fieldValue = FFI::string($this->libssl->ASN1_STRING_get0_data($data), $length);
            $fieldType = $this->libssl->OBJ_nid2ln($this->libssl->OBJ_obj2nid($obj));
            $subjectDetails[$fieldType] = $fieldValue;
        }

        return $subjectDetails;
    }

    /**
     * Gets all issuer fields from the certificate
     *
     * @return array Associative array of issuer fields
     * @throws Exception If issuer data cannot be retrieved
     */
    public function getIssuer(): array
    {
        $issuerName = $this->libssl->X509_get_issuer_name($this->x509);
        if ($issuerName === null) {
            throw new Exception("Issuer name is null");
        }

        $issuerFields = [];
        $entryCount = $this->libssl->X509_NAME_entry_count($issuerName);
        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $this->libssl->X509_NAME_get_entry($issuerName, $i);
            $nid = $this->libssl->X509_NAME_ENTRY_get_object($entry);
            $fieldType = $this->libssl->OBJ_nid2ln($nid->nid);
            $data = $this->libssl->X509_NAME_ENTRY_get_data($entry);
            $length = $this->libssl->ASN1_STRING_length($data);
            $fieldValue = FFI::string($this->libssl->ASN1_STRING_get0_data($data), $length);
            $issuerFields[$fieldType] = $fieldValue;
        }

        return $issuerFields;
    }

    /**
     * Gets a hash of the subject name
     *
     * @return int The subject name hash
     */
    public function subjectNameHash(): int
    {
        return $this->libssl->X509_subject_name_hash($this->x509);
    }

    /**
     * Gets the signature algorithm used by the certificate
     *
     * @return string The algorithm name
     * @throws \InvalidArgumentException If the algorithm is unknown
     */
    public function getSignatureAlgorithm(): string
    {
        $sigAlg = $this->libssl->X509_get0_tbs_sigalg($this->x509);
        $alg = $this->libssl->new("ASN1_OBJECT*");
        $this->libssl->X509_ALGOR_get0(FFI::addr($alg), null, null, $sigAlg);

        $nid = $this->libssl->OBJ_obj2nid($alg);
        if ($nid == 0) {
            throw new InvalidArgumentException("Undefined signature algorithm");
        }

        return $this->libssl->OBJ_nid2ln($nid);
    }
}