# Bitrix-InVoice Bridge

A bridge between **InVoice** (Enel Campaign Orchestrator / ECO) and **Bitrix24**, designed for production use with emphasis on reliability, security, and observability.

## Overview

This project provides a complete bridge between InVoice (Enel Campaign Orchestrator / ECO) and Bitrix24. The system receives webhook events from InVoice (e.g., `LEAD_AVAILABLE`), retrieves lead data from the InVoice API, and automatically creates or updates entities in Bitrix24 (leads or contacts with linked deals).

### Current Status

**Phase 1 (Implemented)**:
- ✅ Webhook endpoint for receiving InVoice events
- ✅ OAuth2 JWT Bearer authentication for InVoice API
- ✅ Forensic logging with sensitive data masking
- ✅ API client for InVoice API calls

**Phase 2 (Implemented)**:
- ✅ Bitrix24 integration for lead/contact synchronization
- ✅ Event processing and business logic
- ✅ Support for both leads and contacts with linked deals
- ✅ Configurable duplicate checking
- ✅ Campaign name mapping from InVoice campaign IDs
- ✅ Custom fields configuration for reverse flow (InVoice references stored on Bitrix deals)

**Phase 3 (Implemented)**:
- ✅ Reverse flow: Bitrix24 → InVoice worked upload
- ✅ Webhook endpoint for Bitrix24 automation (`public/bitrix-webhook.php`)
- ✅ Custom fields mapping configuration (`config/bitrix_fields.php`)
- ✅ Automatic storage of InVoice references (ID_ANAGRAFICA, id_campagna, creation_date, DATA_SCADENZA) on Bitrix deals
- ✅ Pipeline filtering for reverse flow processing

**Phase 3 (In Progress)**:
- 🚧 Reverse flow: Bitrix24 → InVoice worked upload (calls/activities)

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
├── config/              # Configuration files
│   ├── campaigns.php   # Campaign ID to name mapping (not committed, copy from example)
│   ├── campaigns.example.php  # Example campaign mapping template
│   ├── bitrix_fields.php  # Bitrix24 custom field IDs mapping (not committed, copy from example)
│   ├── bitrix_fields.example.php  # Example custom fields mapping template
│   ├── result_codes.php  # Bitrix outcome → InVoice result codes mapping (not committed, copy from example)
│   └── result_codes.example.php  # Example result codes mapping template
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

#### Bitrix24 Integration Configuration
- `BITRIX24_WEBHOOK_URL`: Webhook URL for Bitrix24 REST API
  - Format: `https://[your-domain].bitrix24.it/rest/[user_id]/[webhook_token]/`
  - Create a webhook in Bitrix24: Settings > CRM > Webhooks
- `ENTITY_TYPE`: Type of entity to create in Bitrix24
  - `contact`: Creates/updates a contact and creates a linked deal (default)
  - `lead`: Creates/updates a lead
- `ALLOW_DUPLICATE`: Whether to allow duplicate entities
  - `true`: Allow duplicates, skip duplicate checking (default)
  - `false`: Check for existing leads/contacts by phone before creating
- `PIPELINE`: Pipeline ID for deals (only used when `ENTITY_TYPE=contact`)
  - Set to `0` or leave empty to use default pipeline
  - Example: `PIPELINE=1` for a specific pipeline

#### Bitrix → InVoice (Reverse Flow)
- `BITRIX_WEBHOOK_TOKEN`: Shared secret token for authenticating Bitrix calls to `public/bitrix-webhook.php`
  - Can be passed via HTTP header: `x-api-auth-token: <token>` (recommended)
  - Or via body field: `auth[application_token]: <token>` (Bitrix native format, also supported)
- `PIPELINE_ACTIVITY`: Pipeline ID (Bitrix `CATEGORY_ID`) for filtering activities. Only activities linked to deals in this pipeline will be processed. If empty/0, all pipelines are accepted.
- `BITRIX_OUT_PIPELINE`: **Deprecated** - Use `PIPELINE_ACTIVITY` instead. Restrict processing to a specific deal pipeline (Bitrix `CATEGORY_ID`). If empty/0, all pipelines are accepted.

#### Application Configuration
- `APP_ENV`: Application environment (`production`, `development`, `staging`)
- `LOG_DIR`: Directory path for log files (default: `storage/logs/invoice-webhook`)

### Optional Environment Variables

- `APP_DEBUG`: Enable debug mode (default: `false`)
- `INVOICE_ACCESS_TOKEN`: Direct access token (if already obtained, skips OAuth2 flow)
- `CHECK_PHONE_PREFIX`: Enable automatic phone prefix normalization (default: `false`)
  - If `true`, phone numbers are normalized with the prefix specified in `PHONE_PREFIX`
  - If `false` or `PHONE_PREFIX` is empty, phone numbers are saved as received
- `PHONE_PREFIX`: International phone prefix to add (e.g., `+39` for Italy, `+33` for France)
  - Only used if `CHECK_PHONE_PREFIX=true`
  - Format: `+39` or `39` (both accepted, `+` will be added automatically if missing)

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

## Bitrix24 Integration

### Entity Types

The system supports two entity types in Bitrix24:

#### Contact Mode (`ENTITY_TYPE=contact`) - Default
- Creates or updates a **contact** in Bitrix24
- Automatically creates a **deal** linked to the contact
- The deal uses the pipeline specified in `PIPELINE` environment variable
- Suitable for more advanced CRM workflows where contacts and deals are managed separately
- **This is the default behavior**

#### Lead Mode (`ENTITY_TYPE=lead`)
- Creates or updates a **lead** in Bitrix24
- Suitable for initial lead capture and qualification

### Duplicate Handling

When `ALLOW_DUPLICATE=true` (default):
- No duplicate checking is performed
- New entities are always created, even if duplicates exist
- **This is the default behavior**

When `ALLOW_DUPLICATE=false`:
- The system checks for existing leads/contacts by phone number before creating
- If a duplicate is found, the existing entity is updated instead of creating a new one
- This prevents duplicate entries in Bitrix24

### Campaign Mapping

The system automatically maps InVoice `id_config_campagna` to Bitrix24 `SOURCE_DESCRIPTION` field using the campaign mapping configuration file.

#### Configuration File: `config/campaigns.php`

This file contains an associative array mapping campaign IDs to campaign names. The file is **not** committed to the repository for security and customization reasons.

**To configure**:
1. Copy `config/campaigns.example.php` to `config/campaigns.php`
2. Edit `config/campaigns.php` and add your campaign ID to name mappings:

```php
<?php
return [
    65704 => 'Campaign Name 1',
    65705 => 'Campaign Name 2',
    // Add more mappings as needed
];
```

**Fallback Behavior**: If an `id_config_campagna` is not found in the mapping, the ID itself is used as the campaign name (e.g., `"65704"`).

**Note**: The `config/campaigns.php` file is ignored by Git (see `.gitignore`). Use `config/campaigns.example.php` as a template.

### Custom Fields Configuration

The reverse flow (Bitrix → InVoice) requires **four custom fields** to be created in Bitrix24 on the **Deal** entity. These fields store InVoice references needed to upload "worked" contacts back to InVoice.

#### Required Custom Fields

| Field Name | Type | Purpose | Example Field ID |
|------------|------|---------|------------------|
| **Id Anagrafica Invoice** | Double | Stores InVoice `ID_ANAGRAFICA` | `UF_CRM_1762455213` |
| **Id Campagna Invoice** | String | Stores InVoice `id_campagna` | `UF_CRM_1768978874430` |
| **Data Inizio Invoice** | DateTime | Stores InVoice `creation_date` from slice | `UF_CRM_1762868578` |
| **Data Fine Invoice** | DateTime | Stores InVoice `DATA_SCADENZA` (converted) | `UF_CRM_1762868603` |

#### Configuration File: `config/bitrix_fields.php`

This file maps the custom field names to their actual Bitrix24 field IDs. The file is **not** committed to the repository for security and customization reasons.

**To configure**:
1. Copy `config/bitrix_fields.example.php` to `config/bitrix_fields.php`
2. Edit `config/bitrix_fields.php` and update with your actual field IDs:

```php
<?php
return [
    'id_anagrafica' => 'UF_CRM_1762455213',      // Your actual field ID
    'id_campagna' => 'UF_CRM_1768978874430',     // Your actual field ID
    'data_inizio' => 'UF_CRM_1762868578',        // Your actual field ID
    'data_fine' => 'UF_CRM_1762868603',          // Your actual field ID
];
```

**To find your custom field IDs in Bitrix24**:
1. Go to **Settings > CRM > Custom Fields**
2. Find the field and check its **"CODE"** (e.g., `UF_CRM_1762455213`)

**Note**: The `config/bitrix_fields.php` file is ignored by Git (see `.gitignore`). Use `config/bitrix_fields.example.php` as a template.

#### Configuration File: `config/result_codes.php`

This file maps Bitrix24 activity outcomes/statuses to InVoice `workedCode` and `resultCode`. This mapping is required for the reverse flow (Bitrix → InVoice) to automatically translate Bitrix activity results into InVoice worked contact codes.

**To configure**:
1. Copy `config/result_codes.example.php` to `config/result_codes.php`
2. Edit `config/result_codes.php` and add your mappings for each campaign:

```php
<?php
return [
    // Campaign configuration ID: 65704
    65704 => [
        [
            'bitrix_outcome' => 'SUCCESS',
            'workedCode' => 'W01',
            'resultCode' => 'RC01',
            'workedType' => 'CALL',
            'description' => 'Chiamata completata con successo',
        ],
        [
            'bitrix_outcome' => 'FAILED',
            'workedCode' => 'W02',
            'resultCode' => 'RC02',
            'workedType' => 'CALL',
            'description' => 'Chiamata fallita',
        ],
        // Add more mappings as needed
    ],
    
    // Default mapping (used when no campaign-specific mapping exists)
    'default' => [
        // ...
    ],
];
```

**To find available InVoice result codes for a campaign**:
```bash
php scripts/fetch_result_codes.php <id_config_campagna>
```

This script will display all available `workedCode` and `resultCode` values for the specified campaign, which you can then map to your Bitrix outcomes.

**How it works**:
- When Bitrix calls the webhook with an activity outcome, the system looks up the mapping in `config/result_codes.php`
- It first tries to find a campaign-specific mapping (using `id_config_campagna` from the deal)
- If not found, it falls back to the `default` mapping
- If no mapping is found, explicit `workedCode`/`resultCode` values must be provided in the webhook payload

**Note**: The `config/result_codes.php` file is ignored by Git (see `.gitignore`). Use `config/result_codes.example.php` as a template.

### Phone Number Normalization

The system can automatically normalize phone numbers by adding an international prefix before saving them to Bitrix24.

#### Configuration

Add to your `.env` file:

```bash
# Enable phone prefix normalization
CHECK_PHONE_PREFIX=true

# International phone prefix to add (e.g., +39 for Italy)
PHONE_PREFIX=+39
```

#### How It Works

- **If `CHECK_PHONE_PREFIX=true` and `PHONE_PREFIX` is set**:
  - The system checks if the phone number already has the international prefix
  - If the prefix is already present, the phone number is left unchanged
  - If the prefix is missing, it is automatically added
  - Supports multiple formats:
    - Numbers starting with `+39` → left as is
    - Numbers starting with `0039` → converted to `+39`
    - Numbers starting with `39` → converted to `+39`
    - Numbers starting with `0` → `0` is removed and prefix is added (e.g., `0123456789` → `+39123456789`)

- **If `CHECK_PHONE_PREFIX=false` or `PHONE_PREFIX` is empty**:
  - Phone numbers are saved exactly as received from InVoice
  - No normalization is performed

#### Examples

| Input | `CHECK_PHONE_PREFIX=true`, `PHONE_PREFIX=+39` | Output |
|-------|-----------------------------------------------|--------|
| `330597037` | ✅ | `+39330597037` |
| `+39330597037` | ✅ | `+39330597037` (unchanged) |
| `00339330597037` | ✅ | `+39330597037` (converted) |
| `39330597037` | ✅ | `+39330597037` |
| `0330597037` | ✅ | `+39330597037` (leading 0 removed) |

**Note**: Phone normalization is also applied when searching for existing leads/contacts by phone number, ensuring consistent matching.

### Field Mapping (InVoice → Bitrix24)

The following InVoice fields are automatically mapped to Bitrix24:

| InVoice Field | Bitrix24 Field | Notes |
|--------------|----------------|-------|
| `TELEFONO` | `PHONE` | Phone number array with VALUE and VALUE_TYPE (normalized with prefix if enabled) |
| `ID_ANAGRAFICA` | Custom field (Double) | Mapped via `config/bitrix_fields.php` → `id_anagrafica` |
| `id_campagna` | Custom field (String) | Mapped via `config/bitrix_fields.php` → `id_campagna` |
| `creation_date` | Custom field (DateTime) | Mapped via `config/bitrix_fields.php` → `data_inizio` |
| `DATA_SCADENZA` | Custom field (DateTime) | Mapped via `config/bitrix_fields.php` → `data_fine`, converted from DD/MM/YYYY to YYYY-MM-DD 00:00:00 |
| `id_config_campagna` | `SOURCE_DESCRIPTION` | Mapped via `config/campaigns.php` |
| Lot ID | `COMMENTS` | Stored in comments field |

**Note**: All custom fields must be created in Bitrix24 before use. See Bitrix24 documentation for creating custom fields.

### Reverse Flow: Bitrix24 → InVoice

The system supports a **reverse flow** where Bitrix24 calls our webhook endpoint (`public/bitrix-webhook.php`) when a call/activity is completed, and we automatically upload a "worked contact" to InVoice.

#### How It Works

1. **Bitrix24 Automation**: Configure a Robot/Business Process/Outgoing Webhook in Bitrix24 that triggers on "Activity updated" or "Activity completed" events
2. **Webhook Call**: Bitrix24 makes an HTTP POST to `https://yourdomain.com/bitrix-invoice-bridge/public/bitrix-webhook.php` with:
   - **Authentication** (one of the following):
     - Header: `x-api-auth-token: <BITRIX_WEBHOOK_TOKEN>` (custom header, recommended)
     - Body field: `auth[application_token]: <BITRIX_WEBHOOK_TOKEN>` (Bitrix native format, also supported)
   - **Payload**: Deal/Activity information
     - Format: `application/x-www-form-urlencoded` (Bitrix native webhook format) or `application/json`
     - The webhook automatically parses both formats
     - Common fields: `event`, `data[FIELDS][ID]`, `auth[domain]`, `auth[application_token]`, etc.
3. **Processing**: Our webhook:
   - Validates authentication
   - Extracts `deal_id` from payload
   - Fetches activity details using `crm.activity.get` (verifies `OWNER_TYPE_ID=2` for Deal)
   - Fetches deal from Bitrix24 API using `crm.deal.get`
   - Filters by pipeline (if `PIPELINE_ACTIVITY` is configured, checks deal `CATEGORY_ID`)
   - Reads InVoice references from custom fields (`id_anagrafica`, `id_campagna`)
   - Extracts activity outcome and deal status
   - Builds "worked" payload and submits to InVoice API (`POST /partner-api/v5/worked`)

#### Configuration

Add to your `.env` file:

```bash
# Bitrix -> InVoice reverse flow
BITRIX_WEBHOOK_TOKEN=your-shared-secret-token-here
PIPELINE_ACTIVITY=5  # Optional: only process activities linked to deals in this pipeline (CATEGORY_ID)
```

#### Bitrix24 Automation Setup

**Option 1: Outgoing Webhook (Recommended)**
1. Go to **Settings > CRM > Webhooks**
2. Create an **Outgoing Webhook**
3. Set trigger: **"Activity updated"** or **"Activity completed"**
4. Set URL: `https://yourdomain.com/bitrix-invoice-bridge/public/bitrix-webhook.php`
5. Add header: `x-api-auth-token: <BITRIX_WEBHOOK_TOKEN>`
6. Configure payload to include `deal_id` (or let the webhook extract it from activity owner)

**Option 2: Robot/Business Process**
1. Create a Robot or Business Process
2. Trigger: **"Activity updated"** or **"Activity completed"**
3. Action: **"HTTP Request"** (POST)
4. URL: `https://yourdomain.com/bitrix-invoice-bridge/public/bitrix-webhook.php`
5. Headers: `x-api-auth-token: <BITRIX_WEBHOOK_TOKEN>`
6. Body: Include `deal_id` and any additional fields (e.g., `workedCode`, `resultCode`, `workedType`, `caller`, `workedDate`, `workedEndDate`)

**Result Codes Mapping**:
- The webhook automatically maps Bitrix activity outcomes to InVoice `workedCode`/`resultCode` using `config/result_codes.php`
- The webhook extracts the outcome from the payload (supports multiple formats: `outcome`, `status`, `result`, or from `data.FIELDS.RESULT`/`data.FIELDS.STATUS`)
- If a mapping is found, it automatically sets `workedCode`, `resultCode`, and `workedType`
- If no mapping is found, explicit values must be provided in the webhook payload
- Explicit values in the payload always override the mapping

**To configure outcome mapping**:
1. Copy `config/result_codes.example.php` to `config/result_codes.php`
2. Add mappings for each campaign configuration ID
3. Use `php scripts/fetch_result_codes.php <id_config_campagna>` to find available InVoice codes

## Local Development

### Running Locally

1. **Start a local PHP development server**:
   ```bash
   php -S localhost:8000 -t public
   ```

2. **Access the application**:
   - Webhook endpoints will be available at `http://localhost:8000/invoice-webhook.php`
   - Bitrix reverse-flow endpoint will be available at `http://localhost:8000/bitrix-webhook.php`

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
- `Bitrix24ApiClientTest`:
  - Campaign name mapping and fallback behavior
  - Field mapping for leads and contacts
  - Deal field mapping with and without pipeline
  - Date format conversion (DD/MM/YYYY to YYYY-MM-DD)
  - Empty data handling
- `BitrixToInvoiceWorkedMapperTest`:
  - Validation and payload building for `POST /partner-api/v5/worked` (no network calls)

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

After deployment, you can verify that sensitive files are protected by checking that:
- `.env` files return 403/404 when accessed via web
- `src/` directory is not accessible
- `storage/` directory is not accessible
- Configuration files are not exposed

All sensitive files are protected via `.htaccess` rules in the project structure.

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
