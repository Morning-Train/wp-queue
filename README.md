# Morningtrain\WP\CLICommand

Helper for register and work with WP CLI commands.

## Getting started

To get started with the module simply construct an instance of `\Morningtrain\WP\CLICommand\Module()` and pass it to the `addModule()` method on your project instance.

### Example

```php
// functions.php
require __DIR__ . "/vendor/autoload.php";

use Morningtrain\WP\Core\Theme;

Theme::init();

// Add our module
Theme::getInstance()->addModule(new \Morningtrain\WP\CLICommand\Module());
```

## Use outside project (other modules)
You can call `\Morningtrain\WP\CLICommand\Module::registerFolder($folder_path)` where `$folder_path` is path to folder where command classes are.

### Example
```php
// Module.php
class Module extends AbstractModule {
    public function init() {
        parent::init();

        $this->registerNamedDir('wp-cli_commands', 'Commands');

        \Morningtrain\WP\CLICommand\Module::registerFolder($this->getNamedDirPath('wp-cli_commands'));
    }
}
```

## Create a command
Commands can be created by placing a Command class inside your registered folder, or if in project context you have to place it inside the `Commands` folder.

Extend the `\Morningtrain\WP\CLICommand\Abstracts\CLICommand` class and make sure to use the correct comments for WP CLI to handle it correctly.

See https://make.wordpress.org/cli/handbook/guides/commands-cookbook/ for more info about WP CLI command creation.

### Example
```php
// Commands/TestCommand.php

class TestCommand extends CLICommand {

    protected static $command = 'test';

    /**
     * Starts a test
     *
     * ## OPTIONS
     *
     * <name>
     * : Class name of the test
     *
     * ## EXAMPLES
     *      wp test start fooBar
     *
     * @after_wp_load
     */
    public function start($args, $assoc_args) {
        \WP_CLI::log("Starting test: {$args[0]}");
        
        // Handle your test here
    }
}
```