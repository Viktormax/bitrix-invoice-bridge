# Bitrix-InVoice Bridge

A bridge between **InVoice** (Enel Campaign Orchestrator / ECO) and **Bitrix24**, designed for production use with emphasis on reliability, security, and observability.

## Overview

This project provides the foundation for integrating InVoice and Bitrix24. Currently, the system receives webhook events from InVoice (e.g., `LEAD_AVAILABLE`) with comprehensive forensic logging. The integration with Bitrix24 for processing and synchronizing lead data is planned for future implementation.

### Current Status

**Phase 1 (Implemented)**:
- ✅ Webhook endpoint for receiving InVoice events
- ✅ OAuth2 JWT Bearer authentication for InVoice API
- ✅ Forensic logging with sensitive data masking
- ✅ API client for InVoice API calls

**Phase 2 (Planned)**:
- ⏳ Bitrix24 integration for lead synchronization
- ⏳ Event processing and business logic
- ⏳ Retry mechanisms and idempotency
- ⏳ Bidirectional synchronization

### Key Principles

- **Security**: Token-based authentication, input validation, and secure handling of sensitive data
- **Comprehensive Logging**: Detailed forensic logging for debugging, auditing, and monitoring
- **Production-Ready**: Optimized for InVoice timeout requirements (3s connection, 5s read)
- **Extensible**: Designed to support future Bitrix24 integration and event processing

## Requirements

- **PHP**: >= 7.4
- **Composer**: For dependency management
- **Web Server**: Apache or Nginx (configured for SiteGround hosting)
- **HTTPS**: Required for webhook endpoints (InVoice requires HTTPS)

## Project Structure

```
/
├── public/              # Public web-accessible files (webhook endpoints)
├── src/                 # Application source code
├── storage/             # Runtime storage (logs, cache, etc.)
│   └── logs/           # Application logs
├── scripts/             # Utility scripts (webhook testing, etc.)
├── tests/               # Unit tests (PHPUnit) - committed to repo
├── tests-manual/        # Manual/exploratory tests - NOT committed
├── vendor/              # Composer dependencies (auto-generated)
├── .env                 # Environment configuration (not committed)
├── .env.example         # Environment template
├── composer.json        # PHP dependencies
├── composer.lock        # Locked dependency versions (committed for reproducible builds)
└── README.md           # This file
```

## Installation

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd bitrix-invoice-bridge
   ```

2. **Install dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure environment**:
   ```bash
   cp .env.example .env
   # Edit .env with your actual configuration values
   ```

4. **Set up directory permissions**:
   ```bash
   chmod -R 755 storage/
   ```

## Configuration

The application is configured via environment variables in the `.env` file. See `.env.example` for available options.

### Required Environment Variables

#### Webhook Configuration
- `INVOICE_WEBHOOK_TOKEN`: Shared secret token for authenticating InVoice webhook requests

#### InVoice API OAuth2 Configuration
- `INVOICE_API_BASE_URL`: Base URL for InVoice API (default: `https://enel.in-voice.it`, alternative: `https://enel.in-voice.biz`)
- `INVOICE_CLIENT_ID`: OAuth2 client ID (provided by Enel)
- `INVOICE_JWK_JSON`: JWK (JSON Web Key) containing RSA private key in JSON format, OR RSA private key in PEM format. 
  - **JWK format**: Must include all RSA components: `n`, `e`, `d`, `p`, `q`, `dp`, `dq`, `qi`
  - **PEM format**: If you have a pre-converted PEM private key, you can use it directly (the code detects PEM format automatically)

#### Application Configuration
- `APP_ENV`: Application environment (`production`, `development`, `staging`)
- `LOG_DIR`: Directory path for log files (default: `storage/logs/invoice-webhook`)

### Optional Environment Variables

- `APP_DEBUG`: Enable debug mode (default: `false`)
- `INVOICE_ACCESS_TOKEN`: Direct access token (if already obtained, skips OAuth2 flow)

## OAuth2 Authentication

The application uses OAuth2 with JWT Bearer client assertion for authenticating with InVoice API.

### How It Works

1. **JWK (JSON Web Key)**: Enel provides a JWK containing RSA public and private key components
2. **JWT Construction**: The application builds a JWT with:
   - Header: `alg=RS256`, `typ=JWT`
   - Claims: `iss=client_id`, `sub=client_id`, `aud=token_url`, `iat`, `exp=iat+300s`, `jti=UUID`
3. **JWT Signing**: The JWT is signed with RS256 using the private key from the JWK
4. **Token Request**: The signed JWT is sent as `client_assertion` to `/oauth2/token` endpoint
5. **Access Token**: The response contains an `access_token` used for API calls

### Token Caching

Access tokens are automatically cached to reduce OAuth2 requests. Tokens are cached until `expires_in - 60 seconds` to ensure freshness.

## Local Development

### Running Locally

1. **Start a local PHP development server**:
   ```bash
   php -S localhost:8000 -t public
   ```

2. **Access the application**:
   - Webhook endpoints will be available at `http://localhost:8000/invoice-webhook.php`

3. **Test the setup**:
   ```bash
   # Test webhook endpoint
   php scripts/send_test_webhook.php http://localhost:8000/invoice-webhook.php your-token
   ```

### Development Workflow

1. Make code changes in the `src/` directory
2. Run unit tests: `vendor/bin/phpunit`
3. Test locally using the provided test scripts
4. Check logs in `storage/logs/` for debugging
5. Commit changes with clear, descriptive messages in English

## Testing

### Running Unit Tests

```bash
# Install dev dependencies (includes PHPUnit)
composer install

# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit tests/InvoiceApiClientTest.php
```

### Test Coverage

The project includes comprehensive unit tests in the `tests/` directory:
- `InvoiceApiClientTest`: 
  - OAuth2 JWT generation (structure, claims, RS256 signature)
  - JWK to PEM conversion
  - Base64URL encoding/decoding
  - Token caching
  - API client functionality
- `WebhookLoggerTest`: 
  - Logging functionality and sensitive data masking
  - Body truncation for large payloads
  - Large body file saving
  - JSON decoding and error handling
  - Remote IP detection (X-Forwarded-For, X-Real-IP)
  - Forensic logging completeness

### Utility Scripts

The `scripts/` directory contains utility scripts:
- `send_test_webhook.php`: Test webhook endpoint with sample payload
- `send_test_webhook.sh`: Shell script alternative for webhook testing

**Note**: Manual/exploratory test scripts are located in `tests-manual/` directory, which is excluded from version control and not deployed to production.

## Deployment (SiteGround)

1. **Upload files** to your SiteGround hosting via SFTP/FTP or Git
2. **Set environment variables** in your hosting control panel or `.env` file
3. **Configure web server** to point document root to the `public/` directory
4. **Set permissions**:
   ```bash
   chmod -R 755 storage/
   chmod 644 .env
   ```
5. **Verify HTTPS** is enabled and working
6. **Test webhook endpoints** using the provided test scripts

## Code Standards

**IMPORTANT**: All code, comments, documentation, variable names, function names, class names, log messages, error messages, and commit messages must be written in **English only**. This ensures consistency, maintainability, and international collaboration.

### Naming Conventions

- **Classes**: PascalCase (e.g., `WebhookLogger`)
- **Functions/Methods**: camelCase (e.g., `logRequest`)
- **Variables**: camelCase (e.g., `$requestId`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `MAX_RETRY_ATTEMPTS`)
- **Files**: Match class names for PSR-4 autoloading

## Security Considerations

### File Protection

The project includes multiple layers of protection to prevent unauthorized access to sensitive files:

- **`.env` files**: Protected via `.htaccess` rules in root, `public/`, and all subdirectories. All variations (`.env`, `.env.*`) are blocked from web access.
- **Source code**: The `src/` directory is completely blocked from web access.
- **Storage directory**: The `storage/` directory (containing logs) is protected from web access.
- **Vendor directory**: Composer dependencies in `vendor/` are protected.
- **Configuration files**: Files like `composer.json`, `.gitignore`, etc. are protected.

### Authentication & Authorization

- All webhook endpoints require authentication via `api-auth-token` header
- Token validation is performed before any request processing
- Invalid or missing tokens result in immediate 401 response

### Data Protection

- Sensitive data in logs is automatically masked (tokens, credentials, etc.)
- Environment variables containing secrets are never committed to version control
- HTTPS is required for all webhook communications (enforced by InVoice)
- Input validation is performed on all incoming data

### Testing Security

After deployment, you can verify that sensitive files are protected by running:

```bash
php tests-manual/test_security.php https://yourdomain.com
```

This script will test that `.env` and other sensitive files return 403/404 instead of exposing their contents.

**Note**: The `test_security.php` script is located in `tests-manual/` directory (not committed to repository).

## Logging

Application logs are stored in `storage/logs/invoice-webhook/` (configurable via `LOG_DIR` environment variable) with automatic daily rotation. Log files are named by date (e.g., `YYYY-MM-DD.log`).

Log entries include:
- Request identifiers (UUID) for tracing
- Timestamps in ISO 8601 format
- Complete request details (method, path, headers, body, IP address)
- JSON decoded payload (if valid JSON)
- Sensitive data automatically masked (tokens, credentials)
- Large bodies (>1MB) saved to separate files in `large-bodies/` subdirectory

## Contributing

1. Follow the code standards (English only)
2. Write clear commit messages in English
3. Run unit tests before committing: `vendor/bin/phpunit`
4. Test your changes locally before committing
5. Ensure all tests pass before pushing
6. Never commit credentials, tokens, or sensitive data
7. Update `.env.example` if adding new environment variables

## License

[Specify your license here]

## Support

For issues, questions, or contributions, please refer to the project repository.
