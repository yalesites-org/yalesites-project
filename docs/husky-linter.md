# Husky and code linter

A pre-commit hook has been configured which runs quality tests on the custom code through a package called [husky](https://typicode.github.io/husky/get-started.html), this task is executes automatically just before starting the commit.

If all the tests on the custom code are correct, the commit is made, otherwise the commit is not applied and the errors shown in the terminal must be corrected.

## Modify default tests

The default tests are located in the file: `.husky/pre-commit`. If you need to modify the behavior of the pre-commit hook, you can edit this file:

```code .husky/pre-commit```

## Run tests manually

If you want to run the default tests manually, you can use the following command:

```.husky/pre-commit```
