YS AI
=====

Yalesites specific use of the ai_engine.

Instructions
------------

These are custom settings for the ai engine to do things outside of the scope of ai_engine and more in scope of YaleSites.

## Running tests

Run the module's PHPUnit tests from the repo root, inside Lando:

```
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon php /app/vendor/bin/phpunit -c /app/phpunit.xml /app/web/profiles/custom/yalesites_profile/modules/custom/ys_ai/tests"
```

Add `--testdox` for a readable, per-test summary of what's covered.
