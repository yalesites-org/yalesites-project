# YS AI System Instructions

Editable AI "system instructions" for YaleSites AI, with local versioning/revision
history and synchronization to an external API.

## Running tests

Run from the repository root, inside Lando:

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon php /app/vendor/bin/phpunit -c /app/phpunit.xml --do-not-cache-result /app/web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_ai_system_instructions/tests --testdox"
```

This runs both the Unit tests (`tests/src/Unit`) and the Kernel test
(`tests/src/Kernel`) for this module. The external system instructions API is never
contacted for real -- `SystemInstructionsApiServiceTest` mocks the HTTP client and
asserts the request/response handling directly.
