# Xdebug with YaleSites

## Purpose

The following should help you set up the YaleSites local environment for a debugging session with the debugging interface of your choice using [xdebug](https://xdebug.org).

## Usage

### Enabling xdebug on YaleSites

```sh
ddev xdebug on
```

### Disabling xdebug on YaleSites

```sh
ddev xdebug off
```

DDEV manages Xdebug natively -- no custom tooling or configuration files are needed.

## Configuring your IDE

### Neovim using nvim-dap

#### Prerequesites

- [Neovim (tested on 0.9+)](https://neovim.io)
- [nvim-dap](https://github.com/mfussenegger/nvim-dap)
- [nvim-dap-ui](https://github.com/rcarriga/nvim-dap-ui) (optional)

#### Installation

- Install the above plugins
- Create a `$XDG_CONFIG/nvim/after/dap.lua` file or modify your existing dap configuration with the following:

```lua
local dap = require('dap')
dap.adapters.php = {
  type = "executable",
  command = "node",
  args = { os.getenv("HOME") .. "/code/vscode-php-debug/out/phpDebug.js" }
}

local drupal_configuration = {
  type = "php",
  request = "launch",
  name = "Listen for Xdebug",
  port = 9003,
  pathMappings = {
    ["/var/www/html/"] = "${workspaceFolder}"
  }
}

dap.configurations.php = {
  drupal_configuration,
}

dap.configurations.twig = {
  drupal_configuration,
}

```

- Visit the file you wish to debug, set breakpoints, start the debugger with your own keybindings, or with the following:
```vim
:lua require('dap').continue()
```
- Visit the local site in your browser, making sure to append the following to your query string:
  - `XDEBUG_SESSION_START=1`
- You should see the debugger stop at your breakpoints

- For twig specific debugging, you'll need to use devel's `devel_breakpoint()` function.  This will breakpoint in that method.  Stepping should finally get you to where the templates are loading that you can dive into.

### VSCode

#### Prerequisites

- [VSCode (tested on of 1.77.3)](https://code.visualstudio.com)
- [PHP Debug extension created by Xdebug (tested on of 1.32.1)](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug)

#### Installation

- Open VSCode by opening a terminal and typing `code .` from the root of your YaleSites project
- Install the above extension
- Create a `.vscode/launch.json` file with the following:

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/var/www/html/": "${workspaceFolder}"
      }
    }
  ]
}
```

#### Usage

- Open VSCode by opening a terminal and typing `code .` from the root of your YaleSites project
- Visit the file you wish to debug, set breakpoints, start the debugger with your own keybindings, or with `F5`
- Visit the local site in your browser, making sure to append the following to your query string:
  - `XDEBUG_SESSION_START=1`
- You should see the debugger stop at your breakpoints

## Notes and Caveats

- Many things are singletons in drupal, so you may need to clear the cache to get the debugger to stop at your breakpoints.
- If when visiting the debug window, you do not see such things as watch, callstack, and variables, you may need to open VSCode from the root of your YaleSites project through the terminal.

## Resources

The following were used to figure out how to implement this solution:

- [Xdebug documentation](https://xdebug.org/docs/remote)
- [DDEV Xdebug documentation](https://ddev.readthedocs.io/en/stable/users/debugging-profiling/step-debugging/)

## Known Issues

- Debugging twig files are not as straightforward as debugging the PHP files.  If you find a more reliable way to do this, please submit a pull request.
- Clearing cache is still an issue and probably should be done before each debugging request.  Again, if you find a way around this, please submit a pull request.

## Contributions

If you have any suggestions or improvements, please feel free to modify this document and submit a pull request.  Please follow the [contributing guidelines](https://github.com/yalesites-org/yalesites-project/blob/develop/docs/CONTRIBUTING.md) from the repository.
