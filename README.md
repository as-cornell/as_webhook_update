[![Latest Stable Version](https://poser.pugx.org/as-cornell/as_webhook_update/v)](https://packagist.org/packages/as-cornell/as_webhook_update)
# AS WEBHOOK UPDATE (as_webhook_update)

## INTRODUCTION

Provides a webhook notification service that sends entity changes (articles, people, taxonomy terms) to remote systems via HTTP webhooks. Uses an OOP architecture with services, extractors, and dependency injection for maintainability and testability.

![Takeoff](https://media0.giphy.com/media/v1.Y2lkPTc5MGI3NjExb3A4eXVjZGpncnQyeDlndzI2ZWdycjZudHlucGxuOGVhMTNtZWwwMCZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/SYWnRxV7gS1x8WTjo6/giphy.gif "send it")

## REQUIREMENTS

### Required Modules
- `drupal/hook_post_action` - Provides post-action hooks for entity operations
- `drupal/key` - Provides secure storage for the authorization token

### System Requirements
- Drupal 9.5+ or Drupal 10+
- PHP 8.0+

## INSTALLATION

### New Installation

1. **Install dependencies:**
   ```bash
   composer require drupal/hook_post_action drupal/key
   ```

2. **Enable the modules:**
   ```bash
   drush en hook_post_action key as_webhook_update -y
   ```

3. **Configure the authorization token:**
   - Navigate to `/admin/config/services/webhook-update`
   - Enter your authorization token
   - Click "Save Configuration"

   The token is securely stored using the Key module and will not be exported with configuration.

4. **Verify the configuration:**
   ```bash
   drush config:get as_webhook_update.domain_config
   ```

### Upgrading from Previous Version

**IMPORTANT:** If you're upgrading from the procedural version to the OOP version, you must manually import the domain configuration:

1. **Clear cache:**
   ```bash
   drush cr
   ```

2. **Import domain configuration:**
   ```bash
   drush config:import --partial --source=modules/custom/as_webhook_update/config/install -y
   ```

   Or if using Lando:
   ```bash
   lando drush config:import --partial --source=/app/web/modules/custom/as_webhook_update/config/install -y
   ```

3. **Verify the config was imported:**
   ```bash
   drush config:get as_webhook_update.domain_config
   ```

   You should see environment-specific webhook URLs.

4. **Migrate the authorization token** (if you had one configured):
   ```bash
   # Get the old token value
   drush cget as_webhook_update.settings token

   # Set it via the new form at /admin/config/services/webhook-update
   # Or via drush:
   drush php-eval "
   \$key = \Drupal\key\Entity\Key::create([
     'id' => 'as_webhook_update_token',
     'label' => 'Webhook Update Authorization Token',
     'key_type' => 'authentication',
     'key_provider' => 'config',
     'key_provider_settings' => ['key_value' => 'YOUR_TOKEN_HERE'],
   ]);
   \$key->save();
   "
   ```

5. **Test the webhooks:**
   - Update an article, person, or taxonomy term
   - Check logs: `drush watchdog:show --type=as_webhook_update`

## CONFIGURATION

### Authorization Token

The webhook authorization token is stored securely via the **Key module** and is NOT exported with configuration:

- **Configure via UI:** `/admin/config/services/webhook-update`
- **Configure via Drush:** See installation instructions above
- **Documentation:** See `KEY_SETUP.md` for detailed setup instructions

### Webhook Destinations

Webhook URLs are configured in `config/install/as_webhook_update.domain_config.yml` and are environment-specific:

- **Local (Lando):** `artsci-*.lndo.site`
- **Dev/Test (Pantheon):** `*-artsci-*.pantheonsite.io`
- **Production:** `*.as.cornell.edu`

The module automatically determines the correct webhook URLs based on the current domain.

## ARCHITECTURE

### OOP Design (v2.0+)

The module uses a service-based architecture with dependency injection:

```
as_webhook_update/
├── src/
│   ├── Service/
│   │   ├── WebhookDispatcherService.php      - Main orchestrator
│   │   ├── HttpClientService.php              - HTTP/cURL handling
│   │   ├── DestinationResolverService.php     - URL resolution
│   │   └── PersonTypeRoutingService.php       - Person routing logic
│   ├── DataExtractor/
│   │   ├── EntityDataExtractorInterface.php   - Common interface
│   │   ├── ArticleDataExtractor.php           - Article data
│   │   ├── PersonDataExtractor.php            - Person data
│   │   ├── MediaReportPersonDataExtractor.php - Simplified person data
│   │   └── TaxonomyTermDataExtractor.php      - Taxonomy data
│   ├── Factory/
│   │   └── EntityExtractorFactory.php         - Extractor selection
│   └── ValueObject/
│       └── WebhookDestination.php             - Destination VO
```

### Hooks

The module implements Drupal hooks that delegate to the dispatcher service:

- `hook_ENTITY_TYPE_postinsert()` - For nodes and taxonomy terms
- `hook_ENTITY_TYPE_postupdate()` - For nodes and taxonomy terms
- `hook_ENTITY_TYPE_postdelete()` - For nodes

### Supported Entities

- **Article nodes** - Sent to articles webhook
- **Person nodes** - Sent to AS, department, and/or media report webhooks based on person type
- **Taxonomy terms** - `academic_interests`, `academic_role`, `research_areas`

## MAINTAINERS

Current maintainers for Drupal 10:

- Mark Wilson (markewilson)

## DOCUMENTATION

- **REFACTORING.md** - Details about the OOP refactoring
- **KEY_SETUP.md** - Complete guide to setting up the authorization token
- **as_webhook_update.services.yml** - Service definitions

## TROUBLESHOOTING

### No webhooks being sent

1. **Check if domain config is imported:**
   ```bash
   drush config:get as_webhook_update.domain_config
   ```

   If it returns "Config does not exist", import it:
   ```bash
   drush config:import --partial --source=modules/custom/as_webhook_update/config/install -y
   ```

2. **Check if authorization token is configured:**
   ```bash
   drush php-eval "\$key = \Drupal::service('key.repository')->getKey('as_webhook_update_token'); echo \$key ? 'Token configured' : 'Token NOT configured';"
   ```

3. **Check the logs:**
   ```bash
   drush watchdog:show --type=as_webhook_update --count=10
   ```

4. **Verify the dispatcher service exists:**
   ```bash
   drush php-eval "echo get_class(\Drupal::service('as_webhook_update.dispatcher'));"
   ```

### HTTP code 0 errors

If webhooks show "HTTP code 0", the destination server is unreachable:

- Verify the destination server is running
- Check network connectivity
- Verify the webhook listener endpoint exists

### Testing webhooks

```bash
# Manually trigger a webhook
drush php-eval "
\$node = \Drupal::entityTypeManager()->getStorage('node')->load(NODE_ID);
\Drupal::service('as_webhook_update.dispatcher')->dispatch(\$node, 'update');
"

# Monitor logs in real-time
drush watchdog:tail --type=as_webhook_update
```