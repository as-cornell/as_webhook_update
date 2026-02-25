# Webhook Authorization Token Setup with Key Module

## Overview

The AS Webhook Update module uses Drupal's **Key module** to securely store the authorization token. This prevents the token from being exported with configuration and committed to version control.

## Why Use the Key Module?

✅ **Security**: Token stored in database, not in exportable config
✅ **Version Control Safe**: Token never appears in YAML files or git history
✅ **Environment Flexibility**: Different tokens for dev/test/production
✅ **Best Practice**: Follows Drupal security standards

## Requirements

The Key module must be installed and enabled:

```bash
# If not already installed
composer require drupal/key

# Enable the module
drush en key -y
```

## Setting Up the Authorization Token

### Method 1: Via Admin UI (Recommended)

1. Navigate to **Configuration → Services → Webhook Update Settings**
   URL: `/admin/config/services/webhook-update`

2. Enter your authorization token in the "Authorization Token" field

3. Click "Save Configuration"

The token will be securely stored in the database using the Key module.

### Method 2: Via Drush

```bash
# Create/update the key programmatically
drush php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_update_token');
if (!\$key) {
  \$key = \Drupal\key\Entity\Key::create([
    'id' => 'as_webhook_update_token',
    'label' => 'Webhook Update Authorization Token',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => 'YOUR_TOKEN_HERE'],
  ]);
} else {
  \$key->setKeyValue('YOUR_TOKEN_HERE');
}
\$key->save();
echo 'Token saved successfully';
"
```

### Method 3: Via Key Module UI

1. Navigate to **Configuration → System → Keys**
   URL: `/admin/config/system/keys`

2. Edit the key with ID `as_webhook_update_token`

3. Update the key value

4. Save

## Verifying the Token

Check that the token is configured correctly:

```bash
# Verify key exists
drush php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_update_token');
echo \$key ? 'Token is configured' : 'Token NOT configured';
"

# Test token retrieval (doesn't display actual value)
drush php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_update_token');
\$value = \$key ? \$key->getKeyValue() : NULL;
echo \$value ? 'Token retrieved successfully (' . strlen(\$value) . ' characters)' : 'No token value';
"
```

## Key Configuration Details

- **Key ID**: `as_webhook_update_token`
- **Key Type**: `authentication`
- **Key Provider**: `config` (database storage)
- **Storage Location**: Database (`key_value` table)

## Security Notes

### ✅ DO:
- Use different tokens for each environment (local, dev, test, production)
- Rotate tokens periodically
- Restrict access to the Key module configuration page
- Use strong, random tokens (32+ characters)

### ❌ DON'T:
- Never commit tokens to version control
- Don't use the same token across all environments
- Don't share tokens in plain text (Slack, email, etc.)
- Don't export the key with configuration

## Environment-Specific Tokens

### Local Development (Lando)

Set up your local token:

```bash
lando drush php-eval "
\$key = \Drupal\key\Entity\Key::create([
  'id' => 'as_webhook_update_token',
  'label' => 'Webhook Update Authorization Token',
  'key_type' => 'authentication',
  'key_provider' => 'config',
  'key_provider_settings' => ['key_value' => 'local-dev-token-12345'],
]);
\$key->save();
"
```

### Pantheon Environments

For Pantheon, you have two options:

#### Option 1: Set via Terminus (Recommended)

```bash
# Set the token in the database via SQL
terminus drush <site>.<env> -- php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_update_token');
if (!\$key) {
  \$key = \Drupal\key\Entity\Key::create([
    'id' => 'as_webhook_update_token',
    'label' => 'Webhook Update Authorization Token',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => 'PRODUCTION_TOKEN_HERE'],
  ]);
} else {
  \$key->setKeyValue('PRODUCTION_TOKEN_HERE');
}
\$key->save();
"
```

#### Option 2: Set via Admin UI

1. Log into Pantheon environment
2. Navigate to `/admin/config/services/webhook-update`
3. Enter token and save

## Troubleshooting

### Token Not Found Error

If you see "Authorization token not configured" in logs:

```bash
# Check if key exists
drush php-eval "var_dump(\Drupal::service('key.repository')->getKey('as_webhook_update_token'));"

# If null, create the key
drush ev "\$key = \Drupal\key\Entity\Key::create(['id' => 'as_webhook_update_token', 'label' => 'Webhook Token', 'key_type' => 'authentication', 'key_provider' => 'config', 'key_provider_settings' => ['key_value' => 'YOUR_TOKEN']]); \$key->save();"
```

### Key Module Not Installed

If you get "service not found" errors:

```bash
# Install and enable Key module
composer require drupal/key
drush en key -y
drush cr
```

### Permission Issues

Ensure users have permission to configure keys:

- Navigate to `/admin/people/permissions`
- Grant "Administer keys" permission to appropriate roles

## Migration from Old Config Storage

If you're upgrading from the old version that stored tokens in config:

```bash
# Export old token value
drush cget as_webhook_update.settings token

# Copy the token value and set it via the new form at:
# /admin/config/services/webhook-update

# Or via drush:
drush php-eval "
\$old_token = \Drupal::config('as_webhook_update.settings')->get('token');
if (\$old_token) {
  \$key = \Drupal\key\Entity\Key::create([
    'id' => 'as_webhook_update_token',
    'label' => 'Webhook Update Authorization Token',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => \$old_token],
  ]);
  \$key->save();
  echo 'Migrated token to Key module';
}
"

# Clean up old config
drush cdel as_webhook_update.settings token
```

## Configuration Export/Import

The Key module ensures tokens are **NOT** exported with configuration:

```bash
# When you export config, the key value is NOT included
drush cex -y

# Check that token is NOT in exported config
grep -r "as_webhook_update_token" config/
# Should show key config but NOT the actual token value
```

This is the desired behavior! Tokens stay in the database and must be set manually in each environment.

---

**Last Updated**: February 25, 2026
**Module Version**: 2.0 (OOP Refactored)
