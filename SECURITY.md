# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |

## Security Best Practices

### Credentials Management

- **Never commit credentials**: All sensitive data (tokens, keys, JWK) must be stored in `.env` file, which is excluded from version control
- **Use `.env.example`**: This file contains only placeholder values and serves as a template
- **Rotate credentials regularly**: Change webhook tokens and OAuth2 credentials periodically
- **Limit access**: Only authorized personnel should have access to production `.env` files

### JWK (JSON Web Key) Security

- **Never log JWK content**: The application automatically masks sensitive data in logs
- **Never expose in errors**: Error messages never include full JWK or token values
- **Secure storage**: Store JWK in `.env` file with restricted file permissions (644)
- **No hardcoding**: JWK must never be hardcoded in source code

### Webhook Security

- **HTTPS required**: All webhook endpoints must use HTTPS (enforced by InVoice)
- **Token validation**: All webhook requests are validated against `INVOICE_WEBHOOK_TOKEN`
- **Timeout protection**: Webhook responses are optimized to meet InVoice timeout requirements (3s connection, 5s read)

### API Security

- **OAuth2 authentication**: All API calls use OAuth2 with JWT Bearer client assertion
- **Token caching**: Access tokens are cached securely in filesystem with automatic expiration
- **No token exposure**: Tokens are never logged or exposed in error messages

### File Protection

- **`.htaccess` rules**: Multiple layers of `.htaccess` protection prevent web access to sensitive files
- **Directory restrictions**: `src/`, `storage/`, `vendor/` directories are blocked from web access
- **Log protection**: Log files are stored in protected directories and excluded from version control

## Reporting a Vulnerability

If you discover a security vulnerability, please **do not** open a public issue. Instead, please contact the project maintainers privately.

## Security Checklist

Before deploying to production:

- [ ] All credentials are in `.env` file (not committed)
- [ ] `.env` file has correct permissions (644)
- [ ] HTTPS is enabled and working
- [ ] Webhook token is strong and unique
- [ ] OAuth2 credentials (client_id, JWK) are valid
- [ ] File permissions are set correctly (`storage/` writable, `.env` readable)
- [ ] Security headers are configured
- [ ] Log files are protected from web access
- [ ] No sensitive data in logs (automatic masking enabled)
