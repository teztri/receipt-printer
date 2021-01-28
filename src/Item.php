<?php

namespace teztri\ReceiptPrinter;

class Item
{
    private $i;
    private $name;
    private $qty;
    private $currency = 'Rp';

    function __construct($i, $name, $qty)
    {
        $this->i = $i;
        $this->name = $name;
        $this->qty = $qty;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function getQty()
    {
        return $this->qty;
    }

    public function __toString()
    {
        $text = addSpaces($this->i, 5) . addSpaces($this->name, 20) . addSpaces($this->qty, 5) . "\n";
        return $text;
    }
}
