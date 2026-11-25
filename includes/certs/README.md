# Database CA certificate

Aiven MySQL connections are TLS-encrypted. The default CA path used by
`includes/db_config.php` is:

```
includes/certs/aiven-ca.pem
```

For local XAMPP development you can download the Aiven CA certificate from the
Aiven console (Service → Overview → Download CA) and save it in this folder as
`aiven-ca.pem`. The certificate is not a secret, but the file is excluded from
Vercel deployments by `.vercelignore`.

For Vercel, either:

1. Set `DB_SSL_CA_PEM` to the full certificate contents (the PHP bootstrap
   writes it to a temporary file at runtime), **or**
2. Leave `DB_SSL=1` without a CA file. The connection is still encrypted, but
   the server certificate is not verified (`DB_SSL_VERIFY` is ignored in that
   case).

Never put database passwords in this folder.
