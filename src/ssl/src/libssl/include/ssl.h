typedef struct ssl_method_st SSL_METHOD;
typedef struct ssl_ctx_st SSL_CTX;
typedef struct x509_store_ctx_st X509_STORE_CTX;
typedef struct ssl_st SSL;
typedef struct bio_st BIO;
typedef struct bio_method_st BIO_METHOD;
typedef struct x509_st X509;
typedef struct x509_store_ctx_st X509_STORE_CTX;
typedef struct evp_md_st EVP_MD;
typedef long time_t;
typedef int (*SSL_verify_cb)(int preverify_ok, X509_STORE_CTX *x509_ctx);
typedef struct { time_t tv_sec; long tv_usec; } timeval;
typedef struct _IO_FILE FILE;
typedef struct ec_key_st EC_KEY;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct x509_st X509;
typedef struct x509_name_st X509_NAME;
typedef int ASN1_BOOLEAN;
struct asn1_string_st {
    int length;
    int type;
    unsigned char *data;
    long flags;
};
typedef struct asn1_string_st ASN1_OCTET_STRING;
typedef struct asn1_string_st ASN1_INTEGER;
typedef struct asn1_string_st ASN1_ENUMERATED;
typedef struct asn1_string_st ASN1_BIT_STRING;
typedef struct asn1_string_st ASN1_OCTET_STRING;
typedef struct asn1_string_st ASN1_PRINTABLESTRING;
typedef struct asn1_string_st ASN1_T61STRING;
typedef struct asn1_string_st ASN1_IA5STRING;
typedef struct asn1_string_st ASN1_GENERALSTRING;
typedef struct asn1_string_st ASN1_UNIVERSALSTRING;
typedef struct asn1_string_st ASN1_BMPSTRING;
typedef struct asn1_string_st ASN1_UTCTIME;
typedef struct asn1_string_st ASN1_TIME;
typedef struct asn1_string_st ASN1_GENERALIZEDTIME;
typedef struct asn1_string_st ASN1_VISIBLESTRING;
typedef struct asn1_string_st ASN1_UTF8STRING;
typedef struct asn1_string_st ASN1_STRING;
typedef int ASN1_BOOLEAN;
typedef int ASN1_NULL;
typedef struct bignum_st BIGNUM;
struct asn1_object_st {
    const char *sn, *ln;
    int nid;
    int length;
    const unsigned char *data;  /* data remains const after init */
    int flags;                  /* Should we free this one */
};
typedef struct asn1_object_st ASN1_OBJECT;
struct X509_extension_st {
    ASN1_OBJECT *object;
    ASN1_BOOLEAN critical;
    ASN1_OCTET_STRING value;
};
typedef struct asn1_type_st ASN1_TYPE;
struct X509_algor_st {
    ASN1_OBJECT *algorithm;
    ASN1_TYPE *parameter;
} /* X509_ALGOR */ ;
typedef struct X509_extension_st X509_EXTENSION;
typedef struct X509_name_entry_st X509_NAME_ENTRY;
typedef struct X509_algor_st X509_ALGOR;

struct tm
{
  int tm_sec;			/* Seconds.	[0-60] (1 leap second) */
  int tm_min;			/* Minutes.	[0-59] */
  int tm_hour;			/* Hours.	[0-23] */
  int tm_mday;			/* Day.		[1-31] */
  int tm_mon;			/* Month.	[0-11] */
  int tm_year;			/* Year	- 1900.  */
  int tm_wday;			/* Day of week.	[0-6] */
  int tm_yday;			/* Days in year.[0-365]	*/
  int tm_isdst;			/* DST.		[-1/0/1]*/
  long int __tm_gmtoff;		/* Seconds east of UTC.  */
  const char *__tm_zone;	/* Timezone abbreviation.  */
};
typedef struct srtp_protection_profile_st {
    const char *name;
    unsigned long id;
} SRTP_PROTECTION_PROFILE;

typedef enum {
    TLS_ST_BEFORE,
    TLS_ST_OK,
    DTLS_ST_CR_HELLO_VERIFY_REQUEST,
    TLS_ST_CR_SRVR_HELLO,
    TLS_ST_CR_CERT,
    TLS_ST_CR_COMP_CERT,
    TLS_ST_CR_CERT_STATUS,
    TLS_ST_CR_KEY_EXCH,
    TLS_ST_CR_CERT_REQ,
    TLS_ST_CR_SRVR_DONE,
    TLS_ST_CR_SESSION_TICKET,
    TLS_ST_CR_CHANGE,
    TLS_ST_CR_FINISHED,
    TLS_ST_CW_CLNT_HELLO,
    TLS_ST_CW_CERT,
    TLS_ST_CW_COMP_CERT,
    TLS_ST_CW_KEY_EXCH,
    TLS_ST_CW_CERT_VRFY,
    TLS_ST_CW_CHANGE,
    TLS_ST_CW_NEXT_PROTO,
    TLS_ST_CW_FINISHED,
    TLS_ST_SW_HELLO_REQ,
    TLS_ST_SR_CLNT_HELLO,
    DTLS_ST_SW_HELLO_VERIFY_REQUEST,
    TLS_ST_SW_SRVR_HELLO,
    TLS_ST_SW_CERT,
    TLS_ST_SW_COMP_CERT,
    TLS_ST_SW_KEY_EXCH,
    TLS_ST_SW_CERT_REQ,
    TLS_ST_SW_SRVR_DONE,
    TLS_ST_SR_CERT,
    TLS_ST_SR_COMP_CERT,
    TLS_ST_SR_KEY_EXCH,
    TLS_ST_SR_CERT_VRFY,
    TLS_ST_SR_NEXT_PROTO,
    TLS_ST_SR_CHANGE,
    TLS_ST_SR_FINISHED,
    TLS_ST_SW_SESSION_TICKET,
    TLS_ST_SW_CERT_STATUS,
    TLS_ST_SW_CHANGE,
    TLS_ST_SW_FINISHED,
    TLS_ST_SW_ENCRYPTED_EXTENSIONS,
    TLS_ST_CR_ENCRYPTED_EXTENSIONS,
    TLS_ST_CR_CERT_VRFY,
    TLS_ST_SW_CERT_VRFY,
    TLS_ST_CR_HELLO_REQ,
    TLS_ST_SW_KEY_UPDATE,
    TLS_ST_CW_KEY_UPDATE,
    TLS_ST_SR_KEY_UPDATE,
    TLS_ST_CR_KEY_UPDATE,
    TLS_ST_EARLY_DATA,
    TLS_ST_PENDING_EARLY_DATA_END,
    TLS_ST_CW_END_OF_EARLY_DATA,
    TLS_ST_SR_END_OF_EARLY_DATA
} OSSL_HANDSHAKE_STATE;

const SSL_METHOD *DTLS_server_method(void); /* DTLS 1.0 and 1.2 */
const SSL_METHOD *DTLS_client_method(void); /* DTLS 1.0 and 1.2 */
const SSL_METHOD *DTLS_method(void); /* DTLS 1.0 and 1.2 */
const BIO_METHOD *BIO_s_mem(void);

SSL_CTX *SSL_CTX_new(const SSL_METHOD *meth);
SSL *SSL_new(SSL_CTX *ctx);
BIO *BIO_new(const BIO_METHOD *type);

/* Context */
long SSL_CTX_ctrl(SSL_CTX *ctx, int cmd, long larg, void *parg);
int SSL_CTX_use_certificate_file(SSL_CTX *ctx, const char *file, int type);
int SSL_CTX_use_PrivateKey_file(SSL_CTX *ctx, const char *file, int type);
void SSL_CTX_set_verify(SSL_CTX *ctx, int mode, SSL_verify_cb callback);
int SSL_CTX_set_cipher_list(SSL_CTX *, const char *str);
int SSL_CTX_set_tlsext_use_srtp(SSL_CTX *ctx, const char *profiles);
void SSL_CTX_set_info_callback(SSL_CTX *ctx, void (*cb) (const SSL *ssl, int type, int val));
void SSL_CTX_free(SSL_CTX *a);
int SSL_CTX_check_private_key(const SSL_CTX *ctx);
int SSL_CTX_use_certificate(SSL_CTX *ctx, X509 *x);
int SSL_CTX_use_PrivateKey(SSL_CTX *ctx, EVP_PKEY *pkey);

/* SSL */
long SSL_ctrl(SSL *s, int cmd, long larg, void *parg);
void SSL_set_bio(SSL *s, BIO *rbio, BIO *wbio);
void SSL_set_connect_state(SSL *s);
void SSL_set_accept_state(SSL *s);
int SSL_write(SSL *ssl, const void *buf, int num);
int SSL_get_error(const SSL *s, int ret_code);
int SSL_do_handshake(SSL *s);
int SSL_read(SSL *ssl, void *buf, int num);
int SSL_CTX_use_certificate_ASN1(SSL_CTX *ctx, int len, const unsigned char *d);
int SSL_CTX_use_PrivateKey_ASN1(int pk, SSL_CTX *ctx, const unsigned char *d, long len);
int SSL_export_keying_material(SSL *s, unsigned char *out, size_t olen, const char *label, size_t llen, const unsigned char *context, size_t contextlen, int use_context);
const char *SSL_state_string_long(const SSL *s);
const char *SSL_alert_type_string_long(int value);
const char *SSL_alert_desc_string_long(int value);
const char *SSL_get_cipher_list(const SSL *s, int n);
long SSL_get_verify_result(const SSL *ssl);
OSSL_HANDSHAKE_STATE SSL_get_state(const SSL *ssl);
X509 *SSL_get1_peer_certificate(const SSL *s);
int SSL_shutdown(SSL *s);
void SSL_free(SSL *s);
SRTP_PROTECTION_PROFILE *SSL_get_selected_srtp_profile(SSL *s);
/* BIO */
size_t BIO_ctrl_pending(BIO *b);
int BIO_read(BIO *b, void *data, int dlen);
int BIO_write(BIO *b, const void *data, int dlen);
int BIO_test_flags(const BIO *b, int flags);

/* X509 */
X509 *X509_STORE_CTX_get_current_cert(const X509_STORE_CTX *ctx);
const EVP_MD* EVP_get_digestbyname(const char *name);
int X509_digest(const X509 *data, const EVP_MD *type, unsigned char *md, unsigned int *len);
void X509_free(X509 *a);
X509 *X509_new(void);
void X509_free(X509 *x509);
int X509_set_version(X509 *x, long version);
long X509_get_version(const X509 *x);
ASN1_INTEGER *X509_get_serialNumber(X509 *x);
int X509_set_serialNumber(X509 *x, ASN1_INTEGER *serial);
int ASN1_INTEGER_set(ASN1_INTEGER *a, long v);
ASN1_TIME *X509_gmtime_adj(ASN1_TIME *s, long adj);
ASN1_TIME *X509_getm_notBefore(X509 *x);
ASN1_TIME *X509_getm_notAfter(X509 *x);
int X509_set_pubkey(X509 *x, EVP_PKEY *pkey);
X509_NAME *X509_get_subject_name(X509 *x);
int X509_NAME_add_entry_by_txt(X509_NAME *name, const char *field, int type, const unsigned char *bytes, int len, int loc, int set);
int X509_set_issuer_name(X509 *x, X509_NAME *name);
int X509_sign(X509 *x, EVP_PKEY *pkey, const void *md);
EVP_PKEY *X509_get_pubkey(X509 *x);
X509_EXTENSION *X509_get_ext(const X509 *x, int loc);
ASN1_OCTET_STRING *X509_EXTENSION_get_data(X509_EXTENSION *ne);
int X509_NAME_entry_count(const X509_NAME *name);
X509_NAME_ENTRY *X509_NAME_get_entry(const X509_NAME *name, int loc);
ASN1_OBJECT *X509_NAME_ENTRY_get_object(const X509_NAME_ENTRY *ne);
ASN1_STRING * X509_NAME_ENTRY_get_data(const X509_NAME_ENTRY *ne);
X509_NAME *X509_get_issuer_name(const X509 *a);
unsigned long X509_subject_name_hash(X509 *x);
const X509_ALGOR *X509_get0_tbs_sigalg(const X509 *x);
void X509_ALGOR_get0(const ASN1_OBJECT **paobj, int *pptype,
                     const void **ppval, const X509_ALGOR *algor);

EC_KEY *EC_KEY_new_by_curve_name(int nid);
int EC_KEY_generate_key(EC_KEY *key);
EVP_PKEY *EVP_PKEY_new(void);
int EVP_PKEY_assign(EVP_PKEY *pkey, int type, void *key);
int ASN1_TIME_to_tm(ASN1_TIME *t, struct tm *tm);
const EVP_MD *EVP_sha256(void);
void EC_KEY_free(EC_KEY *key);

int PEM_write_PrivateKey(FILE *fp, EVP_PKEY *pkey, const void *enc, void *kstr, int klen, void *cb, void *u);
int PEM_write_X509(FILE *fp, X509 *x);
X509 *PEM_read_X509(FILE *fp, X509 **x, void *cb, void *u);

FILE *fopen(const char *pathname, const char *mode);
int fclose(FILE *fp);

BIGNUM *ASN1_INTEGER_to_BN(const ASN1_INTEGER *ai, BIGNUM *bn);
char *BN_bn2dec(const BIGNUM *a);
void BN_free(BIGNUM *a);
int BN_dec2bn(BIGNUM **a, const char *str);
BIGNUM *BN_new(void);
ASN1_INTEGER *BN_to_ASN1_INTEGER(const BIGNUM *bn, ASN1_INTEGER *ai);
int ASN1_TIME_print(BIO *bp, const ASN1_TIME *tm);
ASN1_GENERALIZEDTIME *ASN1_TIME_to_generalizedtime(const ASN1_TIME *t,
                                                   ASN1_GENERALIZEDTIME **out);
const unsigned char *ASN1_STRING_get0_data(const ASN1_STRING *x);
int ASN1_STRING_to_UTF8(unsigned char **out, const ASN1_STRING *in);
int ASN1_STRING_length(const ASN1_STRING *x);
int ASN1_STRING_type(const ASN1_STRING *x);

void CRYPTO_free(void *str, const char *file, int line);
const char *OBJ_nid2ln(int n);
int OBJ_obj2nid(const ASN1_OBJECT *o);
