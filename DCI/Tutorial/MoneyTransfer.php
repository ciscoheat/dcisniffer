<?php

///// Context ///////////////////////////////////////////////////////

interface Source {
    public function increaseBalance(int $amount) : void;
}

interface Destination {
    public function decreaseBalance(int $amount) : void;
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

    // System Operation
    public function transfer() {
        $this->source_withdraw();
    }

    private Source $source;

	protected function source_withdraw() {
		$this->source->decreaseBalance($this->_amount);
		$this->destination_deposit();
	}

	private Destination $destination;

	protected function destination_deposit() {
		$this->destination->increaseBalance($this->_amount);
	}

	private int $_amount;
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

	public function increaseBalance(int $amount) : void {
		$this->_balance += $amount;
	}

	public function decreaseBalance(int $amount) : void {
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
