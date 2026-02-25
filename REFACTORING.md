# AS Webhook Update - OOP Refactoring Complete

## Overview

The `as_webhook_update` module has been successfully refactored from a **690-line procedural architecture** to a clean **object-oriented design** using Drupal best practices.

## What Changed

### Before (Procedural)
- 690 lines in a single `.module` file
- 11 procedural functions with hard-coded dependencies
- Mixed concerns (extraction, HTTP, routing) in one place
- Untestable code with no dependency injection
- Difficult to extend or maintain
- Auth token stored in config (exportable, insecure)

### After (OOP)
- 50 lines in `.module` file (hooks only)
- Service-based architecture with dependency injection
- Strategy pattern for data extraction
- Factory pattern for extractor selection
- Fully testable with mockable dependencies
- Easy to extend with new entity types or destinations
- **Auth token secured via Key module** (database storage, never exported)

## New Architecture

### Core Services (`src/Service/`)

1. **WebhookDispatcherService** - Main orchestrator
   - Receives entity + event from hooks
   - Coordinates entire webhook flow
   - Dependencies: ExtractorFactory, DestinationResolver, HttpClient, PersonTypeRouting, Logger

2. **HttpClientService** - HTTP/cURL abstraction
   - Handles all webhook POST requests
   - Encapsulates cURL configuration
   - Uses auth token from config

3. **DestinationResolverService** - URL resolution
   - Maps current domain to webhook URLs
   - Reads environment config (local/test/production)
   - Returns WebhookDestination value objects

4. **PersonTypeRoutingService** - Business logic for person routing
   - Determines which webhooks receive person data
   - Logic: Faculty → AS + MediaReport + Dept
   - Staff routing based on type

### Data Extractors (`src/DataExtractor/`)

**Interface:** `EntityDataExtractorInterface`
- `supports(EntityInterface $entity): bool`
- `extract(EntityInterface $entity, string $event): string`
- `getEntityType(): string`
- `getBundle(): ?string`

**Concrete Extractors:**
- **ArticleDataExtractor** - Article node data
- **PersonDataExtractor** - Person profile data (complex)
- **MediaReportPersonDataExtractor** - Simplified person data
- **TaxonomyTermDataExtractor** - Taxonomy term data

### Factory (`src/Factory/`)

**EntityExtractorFactory**
- Returns appropriate extractor for given entity
- Uses tagged service pattern (`as_webhook_update.entity_extractor`)
- Injected into dispatcher

### Value Objects (`src/ValueObject/`)

**WebhookDestination**
- Immutable object: `url`, `type`, `schema`
- Type constants: `TYPE_AS_PEOPLE`, `TYPE_DEPT_PEOPLE`, `TYPE_ARTICLES`, `TYPE_MEDIAREPORT`
- Schema constants: `SCHEMA_PEOPLE`, `SCHEMA_AS`

### Configuration

**Domain Configuration** - `config/install/as_webhook_update.domain_config.yml`
- Environment-specific webhook URLs (local, test, production)
- Domain-to-schema mappings
- Version controlled, not in database

**Service Definitions** - `as_webhook_update.services.yml`
- All services registered with dependencies
- Tagged services for extractors
- Logger channel for module

### Hooks (Simplified)

```php
function as_webhook_update_node_postinsert(EntityInterface $entity) {
  \Drupal::service('as_webhook_update.dispatcher')->dispatch($entity, 'create');
}
```

Same pattern for `postupdate`, `postdelete`, and taxonomy hooks.

## Benefits

### Testability
- Each component unit testable with mocked dependencies
- No hard-coded dependencies
- Easy to write integration tests

### Maintainability
- Clear separation of concerns
- Single responsibility principle
- Easy to find and modify code

### Extensibility
- New entity types = new extractor class
- New destinations = config change
- No need to modify existing code

### Standards Compliance
- Follows Drupal 9/10 best practices
- Uses dependency injection throughout
- Service container provides clear dependency graph

### Code Reusability
- Services can be used in drush commands, migrations, etc.
- Extractors can be used independently
- HTTP client can be reused for other webhooks

### Security
- **Key Module Integration**: Auth token stored securely in database
- **Never Exported**: Token never appears in config exports or git
- **Environment Isolation**: Different tokens per environment
- **Best Practices**: Follows Drupal security standards

## Security: Key Module Integration

The module now uses Drupal's **Key module** to store the authorization token:

- ✅ Token stored in **database**, not exportable config
- ✅ Token **never appears in git** or config exports
- ✅ **Environment-specific** tokens (different for local/dev/test/production)
- ✅ **Secure management** via admin UI or drush

### Setting Up the Token

1. Navigate to `/admin/config/services/webhook-update`
2. Enter your authorization token
3. Click "Save Configuration"

The token is now securely stored using the Key module and will never be exported with configuration.

For detailed setup instructions, see [KEY_SETUP.md](KEY_SETUP.md).

## File Structure

```
as_webhook_update/
├── as_webhook_update.module              # 50 lines (hooks only)
├── as_webhook_update.services.yml        # Service definitions
├── config/
│   └── install/
│       └── as_webhook_update.domain_config.yml  # Domain URLs
├── src/
│   ├── Service/
│   │   ├── WebhookDispatcherService.php
│   │   ├── HttpClientService.php
│   │   ├── DestinationResolverService.php
│   │   └── PersonTypeRoutingService.php
│   ├── DataExtractor/
│   │   ├── EntityDataExtractorInterface.php
│   │   ├── ArticleDataExtractor.php
│   │   ├── PersonDataExtractor.php
│   │   ├── MediaReportPersonDataExtractor.php
│   │   └── TaxonomyTermDataExtractor.php
│   ├── Factory/
│   │   └── EntityExtractorFactory.php
│   └── ValueObject/
│       └── WebhookDestination.php
└── src/Form/
    └── WebhookSettingsForm.php          # Unchanged (already OOP)
```

## Testing Checklist

### Unit Testing
- [ ] Test each service in isolation with mocked dependencies
- [ ] Test each extractor with sample entities
- [ ] Compare extractor output with old function output (regression testing)

### Integration Testing
- [ ] Create test entities (nodes, terms)
- [ ] Mock HTTP client to avoid external calls
- [ ] Verify correct extractors selected
- [ ] Verify correct destinations resolved
- [ ] Test full flow: entity → dispatcher → HTTP

### Manual Testing
1. **Article Node**: Create/update article, verify webhook to articles URL
2. **Person Node** (test all person types):
   - Faculty → AS + MediaReport + Dept webhooks
   - College Staff → AS + MediaReport
   - Department Staff → Dept only
   - Graduate Student → Dept only
   - Other Faculty with `as_directory=true` → AS + MediaReport + Dept
   - Other Faculty with `as_directory=false` → Dept only
3. **Person Delete**: Verify delete webhooks sent
4. **Taxonomy Terms**: Create/update academic_interests, academic_role, research_areas
5. **Environments**: Test on local (lando), dev, test Pantheon

## Deployment Notes

### First Deployment
1. Deploy all new files to target environment
2. Clear Drupal cache: `drush cr`
3. Import configuration: `drush cim -y` (if using config management)
4. Test webhook functionality

### Rollback Plan
If issues arise, the old procedural code can be restored from git history. However, the new OOP code has been carefully designed to match the exact behavior of the old code.

## Migration Strategy

This refactoring was completed in 6 phases:
1. **Phase 1**: Infrastructure Setup (services.yml, config, value objects)
2. **Phase 2**: Core Services (HTTP, DestinationResolver, PersonTypeRouting)
3. **Phase 3**: Data Extraction Layer (extractors, factory)
4. **Phase 4**: Dispatcher Service (main orchestrator)
5. **Phase 5**: Hook Refactoring (delegate to dispatcher)
6. **Phase 6**: Cleanup (remove old functions)

Phases 1-4 were completed while old code remained functional. Phase 5 was the only breaking change.

## Next Steps

1. **Clear cache** after deployment: `drush cr`
2. **Monitor logs** for errors: `drush watchdog:show --type=as_webhook_update`
3. **Test in local environment** before deploying to dev/test/live
4. **Write unit tests** for services and extractors
5. **Write integration tests** for full webhook flow

## Support

For questions or issues with the refactored code, refer to:
- Service definitions: `as_webhook_update.services.yml`
- Domain configuration: `config/install/as_webhook_update.domain_config.yml`
- Dispatcher logic: `src/Service/WebhookDispatcherService.php`

---

**Refactoring completed:** February 25, 2026
**Original code:** 690 lines procedural
**Refactored code:** 50 lines hooks + OOP services
