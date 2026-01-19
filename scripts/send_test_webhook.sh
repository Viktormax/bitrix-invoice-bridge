#!/bin/bash

# Test script to send a sample LEAD_AVAILABLE webhook to the local endpoint.
#
# Usage:
#   ./scripts/send_test_webhook.sh [webhook-url] [auth-token]
#
# Example:
#   ./scripts/send_test_webhook.sh http://localhost/public/invoice-webhook.php your-secret-token

WEBHOOK_URL="${1:-http://localhost/public/invoice-webhook.php}"
AUTH_TOKEN="${2:-your-secret-token-here}"

# Sample LEAD_AVAILABLE payload (based on API documentation v1.4.0)
# Format: { "event": "LEAD_AVAILABLE", "eventDate": "YYYY-MM-DD HH:mm:ss", "slice": [ { "id": 123 } ] }
PAYLOAD=$(cat <<EOF
{
  "event": "LEAD_AVAILABLE",
  "eventDate": "$(date +"%Y-%m-%d %H:%M:%S")",
  "slice": [
    {
      "id": 3706919
    }
  ]
}
EOF
)

echo "Sending test webhook to: $WEBHOOK_URL"
echo "Payload:"
echo "$PAYLOAD"
echo ""

RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" \
  -X POST \
  -H "Content-Type: application/json" \
  -H "api-auth-token: $AUTH_TOKEN" \
  -H "User-Agent: TestScript/1.0" \
  -d "$PAYLOAD" \
  --connect-timeout 5 \
  --max-time 10 \
  "$WEBHOOK_URL")

HTTP_CODE=$(echo "$RESPONSE" | grep -o "HTTP_CODE:[0-9]*" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE:/d')

echo "HTTP Status: $HTTP_CODE"
echo "Response:"
echo "$BODY"
echo ""

if [ "$HTTP_CODE" -ge 200 ] && [ "$HTTP_CODE" -lt 300 ]; then
  echo "✓ Test webhook sent successfully!"
  exit 0
else
  echo "✗ Test webhook failed with HTTP $HTTP_CODE"
  exit 1
fi
