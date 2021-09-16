# DCI conventions for PHP

If you're interested in learning and applying [DCI (Data, Context, Interaction)](http://fulloo.info/Introduction/) in your PHP projects, this can be a way to get started quickly.

This repo contains DCI coding conventions for the code linter PHP_CodeSniffer (phpcs). It will guide you towards best practices, with a well-organized, readable DCI code as a result.

## Getting started

VS Code or any other IDE is optional but recommended, since you get immediate feedback.

1. Install [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer#installation).
1. Clone this repository to a location of your choice.
1. Install IDE support. For VS Code, there are several extensions that support phpcs, [PHP Sniffer](https://marketplace.visualstudio.com/items?itemName=wongjn.php-sniffer) (wongjn.php-sniffer) works well.
1. Configure the extension, and set the `Standard` setting to the `DCI` folder in the cloned repository. Example: `/projects/dcisniffer/DCI`.

## Enabling the conventions for a PHP class

Now there is only one thing you need to do to enable the conventions, which is to mark the classes you wish to check in a docblock with `@context` or `@DCIContext`:

```php
/**
 * @context
 */
final class MoneyTransfer {
    ...
}
```

## Basic conventions

**Naming:** Roles are created as private properties: `private $source;`. They must be in `camelCase` or `ProperCase` format, and cannot contain underscore.

RoleMethods are then added below the Roles as methods: `protected source_decreaseBalance($amount)`. They always start with the Role name, then any number of underscores, then the method name, in the same format as the Role.

**Access:** A Role can only be accessed within its RoleMethods, which also goes for `private` RoleMethods.

**Assignment:** Roles must all be bound (assigned) within the same method.

The [RoleConventionsSniff](https://github.com/ciscoheat/dcisniffer/blob/master/DCI/Sniffs/RoleConventionsSniff.php) file is written as a DCI Context, albeit at bit contrieved since the pattern used for parsing makes it a bit difficult to handle rebinding.

## MoneyTransfer tutorial

A PHP version of the [haxedci](https://github.com/ciscoheat/haxedci) MoneyTransfer tutorial will follow soon.
