# YaleSites Alert

## Description
The YaleSite Alert Banner module enhances your website by providing a versatile alert banner that can display messages on all pages. Whether you want to make announcements, run marketing campaigns, or communicate emergency information, this module has got you covered.

## Features
- **Alert Management Interface**: We offer a user-friendly admin interface at admin/yalesites/alert for efficient management of alerts.
- **Global Alert Display**: Enabled alerts are prominently displayed across all pages of your website, ensuring your message reaches your audience effectively.
- **Toggle Switch**: A convenient toggle switch allows site owners to enable or disable alerts at their convenience.
- **Alert Types**: Customize your message by specifying the alert type: announcement, marketing, or emergency. Each type has a different visual treatment and mechanism for minimizing or dismissing.
- **Confirmation Message**: For emergency alerts, we've added an extra layer of caution. Authors will receive a confirmation message before setting emergency alerts, helping prevent accidental broadcasts.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_alert/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_alert/tests`.
