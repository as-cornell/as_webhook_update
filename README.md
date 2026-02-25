[![Latest Stable Version](https://poser.pugx.org/as-cornell/as_webhook_update/v)](https://packagist.org/packages/as-cornell/as_webhook_update)
# AS WEBHOOK UPDATE (as_webhook_update)

## INTRODUCTION

Provides a webhook notification service tied into hook_post_action, used to generate remote nodes and terms.

![Carrier Takeoff](https://media0.giphy.com/media/v1.Y2lkPTc5MGI3NjExcHZhcTVlMXdlanY0bWc1cWtlejVha3Zhc2hhdnplNG5iZjZzejR1dCZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/MahFJeuuCBGvoj2OHF/giphy.gif "send it")


## REQUIREMENTS

This module depends on the drupal/hook_post_action module.

## MAINTAINERS

Current maintainers for Drupal 10:

- Mark Wilson (markewilson)

## CONFIGURATION
- Enable the module as you would any other module
- Configure the global module settings: /admin/config/as_webhook_update/settings

## FUNCTIONS

All the functions in this module are in as_webhook_update.module.

- as_webhook_update_node_postinsert
- as_webhook_update_node_postupdate
- as_webhook_update_taxonomy_term_postinsert
- as_webhook_update_taxonomy_term_postupdate
- as_webhook_update_node_postdelete
- as_webhook_update_getarticledata
- as_webhook_update_getmediareportentrydata
- as_webhook_update_getpersondata
- as_webhook_update_getmediareportpersondata
- as_webhook_update_getcurl
- as_webhook_update_getsummary
- as_webhook_update_getbody
- as_webhook_update_gettid
- as_webhook_update_gettidstring
- as_webhook_update_gettermnamestring
- as_webhook_update_getarticleuuiidstring
- as_webhook_update_getpersonlinks
- as_webhook_update_getpersonlinktitles
- as_webhook_update_getdomainschema