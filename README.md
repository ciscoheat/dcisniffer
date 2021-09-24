# DCI conventions for PHP

If you're interested in learning and applying [DCI (Data, Context, Interaction)](http://fulloo.info/Introduction/) in your PHP projects, this can be a way to get started quickly.

This repo contains DCI coding conventions for the code linter PHP_CodeSniffer (phpcs). It will guide you towards best practices, with a well-organized, readable DCI code as a result.

# Getting started

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

# Basic conventions

## Naming

**Roles** are created as private properties: `private $source`. As default they must be in `camelCase` or `ProperCase` format and cannot contain underscore. By prefixing/suffixing a property with underscore however, it will not be treated as a Role but as a normal class property.

**RoleMethods** are added below the Roles as methods: `protected function source_decreaseBalance(int $amount)`. They always start with the Role name, then any number of underscores, then the method name, in the same format as the Role. Methods without underscores are treated as normal class methods.

## Access

A Role can only be accessed within its RoleMethods, and its accessor must always be `private`. A RoleMethod can be accessed from other parts of the Context when it's `protected`, but if it's `private`, only within its other RoleMethods:

```php
private $source;

// Accessed from anywhere in the Context, since it's protected.
protected function source_decreaseBalance(int $amount) {
    ...
}

// Can only be accessed from RoleMethods belonging to the 'source' Role.
private function source_checkBalance() {
    ...
}
```

## Assignment

Roles must all be assigned (bound) within a single method, which commonly is the constructor, but can be moved to another method if needed. See the tutorial further down for more information.

# Configuration

## Custom naming scheme

By creating your own code standard xml file, you can modify the naming format of the Roles and RoleMethods. They are configured by two properties, `roleFormat` and `roleMethodFormat`. They both contain a regexp for extracting the name of the Role and its method. To modify it, create a `phpcs.xml` file in your project directory:

**phpcs.xml**

```xml
<?xml version="1.0"?>
<ruleset name="YourStandard">
    <description>Your custom rules.</description>
    <rule ref="/path/to/dcisniffer/DCI" />
    <rule ref="/path/to/dcisniffer/DCI/Sniffs/RoleConventionsSniff.php">
        <properties>
           <property name="roleFormat" value="/^([a-z]\w+[a-zA-Z0-9])$/" />
           <property name="roleMethodFormat" value="/^([a-z]\w+[a-zA-Z0-9])__(\w+)$/" />
        </properties>
    </rule>
</ruleset>
```

This configuration makes the naming adhere to another common PHP standard, using underscores instead of camelCase. Two underscores are separating the Role name from the method:

`current_method` - Now a valid Role.

`current_method__add_role_ref` - With a RoleMethod called `add_role_ref`.

Note that each name in the regexps must be enclosed with parenthesis.

## Debug configuration

There are two other properties available in the configuration:

`listCallsInRoleMethod` - When set to a RoleMethod, it will list all calls to other RoleMethods.

`listCallsToRoleMethod` - The other way around, this will list all calls to a RoleMethod from other parts of the Context.

## Ignoring properties

If for some reason a Context must contain a public property that must adhere to a certain naming that conflicts with the Role naming, you can ignore that property by tagging it with `@noDCIRole`. Having public properties on a Context is not recommended, since Contexts should be highly encapsulated in general. Data is a more likely candidate for properties (see the Tutorial below for more information).

```php
/**
 * @noDCIRole
 */
public $someOtherProperty;
```

# DCI Tutorial

An ATM money transfer will be our simple DCI example and tutorial.

Let's start with a simple data class called `Account`:

```php
class Account {
    private string $_name;
    private int $_balance;

    public function __construct($name, $balance) {
        $this->_name = $name;
        $this->_balance = $balance;
    }

    public function balance() {
        return $this->_balance;
    }

    public function name() {
        return $this->_name;
    }

    public function increaseBalance(int $amount) {
        $this->_balance += $amount;
    }

    public function decreaseBalance(int $amount) {
        $this->_balance -= $amount;
    }
}
```

This is what we in DCI sometimes call a "dumb" data class. It only "knows" about its own data and trivial ways to manipulate it. The concept of a *transfer* between two accounts is outside its responsibilities and we delegate this to a Context - the `MoneyTransfer` Context class. In this way we can keep the Account class very slim and avoid that it gradually takes on more and more responsibilities for each use case it participates in.

From a users point of view we might think of a money transfer as

- "Move money from one account to another"

and after some more thought specify it further:

- "Withdraw amount from a source account and deposit the amount in a destination account"

That could be our "Mental Model" of a money transfer. Interacting concepts like our "Source" and "Destination" accounts of our mental model we call "Roles" in DCI, and we can define them and what they do to accomplish the money transfer in a DCI Context.

Our source code should map as closely to our mental model as possible so that we can confidently and easily overview and reason about _how the objects will interact at runtime_. We want no surprises at runtime. With DCI we have all runtime interactions right there! No need to look through endless convoluted abstractions, tiers, polymorphism etc to answer the reasonable question *where is it actually happening?!*

## Creating a Context

Lets build the `MoneyTransfer` Context class step-by-step from scratch:

Start by defining a class and add the `@context` docblock.

```php
/**
 * @context
 */
final class MoneyTransfer {
}
```

A DCI Context must be final, since inheritance and polymorphism is part of the previous paradigm, OOP which is actually class-oriented thinking. It may have its place within the system, but a Context represents runtime behaviour of a specific use case, and should not be confused with static hierarchy and structure.

Let's introduce Roles now. Remember the mental model of a money transfer? "Withdraw *amount* from a *source* account and deposit the amount in a *destination* account". The three italicized nouns are the Roles that we will use in the Context. They are defined using private properties:

```php
final class MoneyTransfer {
	private $source;
	private $destination;
	private $amount;
}
```

### Defining a RoleObjectContract

In DCI, the type of a Role is called its **RoleObjectContract**, or just **contract**.

The common thinking is to use the already defined classes. The source and destination Roles could be an `Account`.

We're not interested in the whole `Account` however, that is the old, class-oriented thinking. We want to focus on what happens in the Context right now for a specific Role, so all we need for an object to play the *source* Role is a way of decreasing the balance.

In PHP you could use an interface for this, representing an artefact that makes sense to a user in a Context. Let's define a `Source` interface:

```php
interface Source {
    public function increaseBalance(int $amount);
}
```

So what are the advantages of this? Why not just put the class there and be done with it?

The most obvious advantage is that we're making the Role more generic. Any object fulfilling the type of the RoleObjectContract can now be a money source, not just `Account`.

Another interesting advantage is that when specifying a more compressed contract, we only observe what the Roles can do in the current Context. This is called *"Full OO"*, a powerful concept that you can [read more about here](https://groups.google.com/d/msg/object-composition/umY_w1rXBEw/hyAF-jPgFn4J), but basically, by doing that we don't need to understand `Account`, or essentially anything outside the current Context.

This also affects [locality](http://www.saturnflyer.com/blog/jim/2015/04/21/locality-and-cohesion/), the ability to understand code by looking at only a small portion of it. So plan your public class API, consider what it does, how it's named and why. Then refine your contracts. DCI is as much about clear and readable code as matching a mental model and separating data from function.

### RoleMethods

Now we have the Roles and their contracts for accessing the underlying objects. That's a good start, so lets add the core of a DCI Context: functionality. It is implemented through **RoleMethods**.

Getting back to the mental model again, we know that we want to *"Withdraw amount from a source account and deposit the amount in a destination account"*. So lets model that in a RoleMethod for the `source` Role:

```php
    private Source $source;

    protected function source_withdraw() {
        $this->source->decreaseBalance($this->amount);
        $this->destination_deposit();
    }
}
```

The *withdraw* RoleMethod is a very close mapping of the mental model to code, which is the goal of DCI.

Note how we're using the contract method only for the actual data operation, the rest is functionality, collaboration between Roles through RoleMethods. This collaboration requires a RoleMethod on destination called `deposit`, according to the mental model. Let's define it:

```php
    private Destination $destination;

    protected function destination_deposit() {
        $this->destination->increaseBalance($this->amount);
    }
}
```

### The amount Role

The amount role is special. We're using an `int` in this example, which lack of interfaceability (especially in PHP) means it's not well suited for a Role, as with most basic types. Instead we can make it an ordinary property or pass it as a parameter. Let's use a property in this case.

```php
// Change the property name to:
private int $_amount;
```

We need an underscore in the name so the DCI library won't mistake it for a Role.

(It's worth noting that in a more realistic example, `amount` would probably have some `Currency` class behind it, enabling it to play a Role.)

### Role and RoleMethod access

RoleMethods must be declared `protected` to allow access outside their corresponding Role. Roles should only be accessed within its own RoleMethods however. This enables the ability to trace the flow of cooperation between Roles, instead of any Role being able to call another Roles' underlying object at all times. It's a helpful separation between the local reasoning of how Roles interact locally with their object, and how Roles interact with each other. A goal with DCI is readability, and this helps reading and understanding the use-case-level logic of a Context.

### Adding a constructor - Role binding

```php
final class MoneyTransfer {
    public function __construct($source, $destination, $amount) {
        $this->source = $source;
        $this->destination = $destination;
        $this->_amount = $amount;
    }
}
```

There's nothing special about the constructor, just assign the Roles as normal instance variables. This assignment is called *Role-binding*, and there are two important things to remember about it:

1. All Roles *must* be bound in the same function.
1. A Role *should not* be left unbound (it can be bound to `null` however).

Rebinding individual Roles during executing complicates things, and is hardly supported by any mental model. So put the binding in one place only, you can factorize it out of the constructor to a separate method if you want. The Roles can be rebound before another Interaction in the same Context occurs, which can be useful during recursion for example.

### System Operations

We just mentioned Interactions, which is the last part of the DCI acronym. An **Interaction** is a flow of messages through the Roles in a Context, like the one we have defined now, based on the mental model. To start an Interaction we need an entrypoint for the Context, a public method in other words. This is called a **System Operation**, and it should usually just call a RoleMethod, so the Roles start interacting with each other.

If you're basing the Context on a use case, there is usually only one System Operation in a Context. Let's call it `transfer`. Try not to use a generic name like "execute" or "run", instead give your API meaning by letting every method name carry meaningful information.

**MoneyTransfer.hx**

```php
final class MoneyTransfer {
    // System Operation
    public function transfer() {
        $this->source_withdraw();
    }
}
```

With this System Operation as our entrypoint, the `MoneyTransfer` Context is ready for use! Let's create two accounts and the Context, and finally make the transfer.

**MoneyTransfer.php**

```php
<?php

///// Context ///////////////////////////////////////////////////////

interface Source {
    public function increaseBalance(int $amount);
}

interface Destination {
    public function decreaseBalance(int $amount);
}

/**
 * @context
 */
final class MoneyTransfer {
    public function __construct($source, $destination, $amount) {
        $this->source = $source;
        $this->destination = $destination;
        $this->_amount = $amount;
    }

    public function transfer() {
        $this->source_withdraw();
    }

    private int $_amount;

    ///// Roles /////////////////////////////////////////////////////

    private Source $source;

    protected function source_withdraw() {
        $this->source->decreaseBalance($this->_amount);
        $this->destination_deposit();
    }

    /////////////////////////////////////////////////////////////////

    private Destination $destination;

    protected function destination_deposit() {
        $this->destination->increaseBalance($this->_amount);
    }
}

///// Data //////////////////////////////////////////////////////////

class Account
implements Source, Destination
{
    private string $_name;
    private int $_balance;

    public function __construct($name, $balance) {
        $this->_name = $name;
        $this->_balance = $balance;
    }

    public function balance() {
        return $this->_balance;
    }

    public function name() {
        return $this->_name;
    }

    public function increaseBalance(int $amount) {
        $this->_balance += $amount;
    }

    public function decreaseBalance(int $amount) {
        $this->_balance -= $amount;
    }
}

///// Entrypoint ////////////////////////////////////////////////////

$checking = new Account('Checking', 0);
$savings = new Account('Savings', 1000);

echo "Before transfer:\n";
echo $savings->name() . ': $' . $savings->balance() . "\n";
echo $checking->name() . ': $' . $checking->balance() . "\n";

// Creating and executing the Context:
(new MoneyTransfer($savings, $checking, 500))->transfer();

echo "\nAfter transfer:\n";
echo $savings->name() . ': $' . $savings->balance() . "\n";
echo $checking->name() . ': $' . $checking->balance() . "\n";

```

With the above file, you can now test the example with `php MoneyTransfer.php`.

# Advantages

Ok, we have learned new concepts and a different way of structuring our program. But why should we do all this?

The advantage we get from using Roles and RoleMethods in a Context, is that we know exactly where our functionality is. It's not spread out in multiple classes anymore. When we talk about a "money transfer", we know exactly where in the code it is handled now. Another good thing is that we keep the code simple. No facades, design patterns or other abstractions, just the methods we need.

The Roles and their RoleMethods gives us a view of the Interaction between objects instead of their inner structure. This enables us to reason about *system* functionality, not just class functionality. In other words, DCI embodies true object-orientation where runtime Interactions between a network of objects in a particular Context is understood *and* coded as first class citizens.

We are using the terminology and mental model of the user. We can reason with non-programmers using their terminology, see the responsibility of each Role in the RoleMethods, and follow the mental model as specified within the Context.

DCI is a new paradigm, which forces the mind in different directions than the common OO-thinking. What we call object-orientation today is really class-orientation, since functionality is spread throughout classes, instead of contained in Roles which interact at runtime. When you use DCI to separate Data (RoleObjectContracts) from Function (RoleMethods), you get a beautiful system architecture as a result. No polymorphism, no intergalactic GOTOs (aka virtual methods), everything is kept where it should be, in Context!

## Functionality and RoleMethods

Functionality can change frequently, as requirements changes. The Data however will probably remain stable much longer. An `Account` will stay the same, no matter how fancy web functionality is available. So take care when designing your Data classes. A well-defined Data structure can support a lot of functionality, by playing Roles in Contexts.

When designing functionality using RoleMethods in a Context, be careful not to end up with one big method doing all the work. That is an imperative approach which limits the power of DCI, since we're aiming for communication between Roles, not a procedural algorithm that tells the Roles what to do. Make the methods small, and let the mental model of the Context become the guideline. A [Use case](http://www.usability.gov/how-to-and-tools/methods/use-cases.html) is a formalization of a mental model that is supposed to map to a Context in DCI.

> A difference between [the imperative] kind of procedure orientation and object orientation is that in the former, we ask: _"What happens?"_ In the latter, we ask: _"Who does what?"_ Even in a simple example, a reader looses the "who" and thereby important locality context that is essential for building a mental model of the algorithm. ([From the DCI FAQ](http://fulloo.info/doku.php?id=what_is_the_advantage_of_distributing_the_interaction_algorithm_in_the_rolemethods_as_suggested_by_dci_instead_of_centralizing_it_in_a_context_method))

## Is DCI a silver bullet?

Of course the answer is No, DCI isn't suitable for every problem. DCI is an approach to design that builds on a psychological model of the left-brain/right-brain dichotomy. It is just one model, though a very useful one when working close to users and their needs, especially where the discussions end up in a formalized use case.

Some cases don’t lend themselves very well to use cases but are better modeled by state machines, formal logic and rules, or database tables and transaction semantics. Or just simple, atomic MVC. Chances are though, if you're working with users, domain experts, stakeholders, etc, you'll notice them thinking in Roles, and if you let them do that instead of forcing a class-oriented mental model upon them, they will be happier, and DCI will be a great help!

# Larger examples

- The [RoleConventionsSniff](https://github.com/ciscoheat/dcisniffer/blob/master/DCI/Sniffs/RoleConventionsSniff.php) file is written as a DCI Context, unfortunately a bit contrived since the pattern used for parsing makes it a bit difficult to handle rebinding.

# DCI Resources

## Videos 

['A Glimpse of Trygve: From Class-oriented Programming to Real OO' - Jim Coplien [ ACCU 2016 ]](https://www.youtube.com/watch?v=lQQ_CahFVzw)

[DCI – How to get ahead in system architecture](http://www.silexlabs.org/wwx2014-speech-andreas-soderlund-dci-how-to-get-ahead-in-system-architecture/)

## Links

Website - [fulloo.info](http://fulloo.info) <br>
FAQ - [DCI FAQ](http://fulloo.info/doku.php?id=faq) <br>
Support - [stackoverflow](http://stackoverflow.com/questions/tagged/dci), tagging the question with **dci** <br>
Discussions - [Object-composition](https://groups.google.com/forum/?fromgroups#!forum/object-composition) <br>
Wikipedia - [DCI entry](http://en.wikipedia.org/wiki/Data,_Context,_and_Interaction)

The MoneyTransfer tutorial is converted from the [haxedci](https://github.com/ciscoheat/haxedci) DCI library.
