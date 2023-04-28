# Xdebug with YaleSites

## Purpose

The following should help you set up the YaleSites local environment for a debugging session with the debugging interface of your choice using [xdebug](https://xdebug.org).

## Notes

`xdebug-on` and `xdebug-off` should be located inside the .local.lando.yml file.

## Usage

### Enabling xdebug on YaleSites

```sh
lando xdebug-on
```

The above command will do the following:

- Set the environment variable XDEBUG_MODE within the appserver to debug
- Disables page caching so that the debugger can run on each request
- Removes any previous docker-php-ext-xdebug.ini files that may still exist
- Enables the xdebug extension
- Kills php-fpm so that the new configuration can be loaded
- Clears the cache one more time (just in case)
- Tells you that it has been completed

### Disabling xdebug on YaleSites

```sh
lando xdebug-off
```

The above command will do the following:

- Set the environment variable XDEBUG_MODE within the appserver to `off`
- Enables page caching again as that is the default
- Removes any previous docker-php-ext-xdebug.ini files that may still exist, disabling the extension
- Kills php-fpm so that the new configuration can be loaded
- Clears the cache one more time (just in case)
- Tells you that it has been completed

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
    ["/app/"] = "${workspaceFolder}"
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
        "/app/": "${workspaceFolder}"
      }
    }
  ]
}
```

- Visit the file you wish to debug, set breakpoints, start the debugger with your own keybindings, or with `F5`
- Visit the local site in your browser, making sure to append the following to your query string:
  - `XDEBUG_SESSION_START=1`
- You should see the debugger stop at your breakpoints

## Notes and Caveats

- Many things are singletons in drupal, so you may need to clear the cache to get the debugger to stop at your breakpoints.

## Resources

The following were used to figure out how to implement this solution:

- [Xdebug documentation](https://xdebug.org/docs/remote)
- [Lando documentation](https://docs.lando.dev/guides/lando-phpstorm.html)

## Known Issues

- Debugging twig files are not as straightforward as debugging the PHP files.  If you find a more reliable way to do this, please submit a pull request.
- Clearing cache is still an issue and probably should be done before each debugging request.  Again, if you find a way around this, please submit a pull request.

## Contributions

If you have any suggestions or improvements, please feel free to modify this document and submit a pull request.  Please follow the [contributing guidelines](https://github.com/yalesites-org/yalesites-project/blob/develop/docs/CONTRIBUTING.md) from the repository.
