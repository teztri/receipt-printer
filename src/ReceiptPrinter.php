<?php

namespace teztri\ReceiptPrinter;

use teztri\ReceiptPrinter\Item as Item;
use teztri\ReceiptPrinter\Store as Store;
use Mike42\Escpos\Printer;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\CupsPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

class ReceiptPrinter
{
    private $printer;
    private $logo;
    private $store;
    private $items;
    private $currency = 'Rs';
    private $subtotal = 0;
    private $tax_percentage = 10;
    private $tax = 0;
    private $grandtotal = 0;
    private $request_amount = 0;
    private $qr_code = [];
    private $transaction_id = '';

    function __construct()
    {
        $this->printer = null;
        $this->items = [];
    }

    public function close()
    {
        $this->printer->close();
    }

    public function init($connector_type, $connector_descriptor, $connector_port = 9100)
    {
        switch (strtolower($connector_type)) {
            case 'cups':
                $connector = new CupsPrintConnector($connector_descriptor);
                break;
            case 'windows':
                $connector = new WindowsPrintConnector($connector_descriptor);
                break;
            case 'network':
                $connector = new NetworkPrintConnector($connector_descriptor);
                break;
            default:
                $connector = new FilePrintConnector("php://stdout");
                break;
        }

        if ($connector) {
            // Load simple printer profile
            $profile = CapabilityProfile::load("default");
            // Connect to printer
            $this->printer = new Printer($connector, $profile);
        } else {
            throw new Exception('Invalid printer connector type. Accepted values are: cups');
        }
    }

    public function setStore($mid, $name, $address, $phone, $email, $website)
    {
        $this->store = new Store($mid, $name, $address, $phone, $email, $website);
    }

    public function setLogo($logo)
    {
        $this->logo = $logo;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function addItem($i, $name, $qty)
    {
        $item = new Item($i, $name, $qty);
        $item->setCurrency($this->currency);

        $this->items[] = $item;
    }

    public function setRequestAmount($amount)
    {
        $this->request_amount = $amount;
    }

    public function setTax($tax)
    {
        $this->tax = $tax;
    }

    public function calculateSubtotal()
    {
        $this->subtotal = 0;

        foreach ($this->items as $item) {
            $this->subtotal += (int) $item->getQty() * (int) $item->getPrice();
        }
    }

    public function calculateGrandTotal()
    {
        if ($this->subtotal == 0) {
            $this->calculateSubtotal();
        }

        $this->grandtotal = (int) $this->subtotal + (int) $this->tax;
    }

    public function setTransactionID($transaction_id)
    {
        $this->transaction_id = $transaction_id;
    }

    public function setQRcode($content)
    {
        $this->qr_code = $content;
    }

    public function getPrintableQRcode()
    {
        return json_encode($this->qr_code);
    }

    public function getPrintableHeader($left_text, $right_text, $is_double_width = false)
    {
        $cols_width = $is_double_width ? 8 : 15;

        return str_pad($left_text, $cols_width) . str_pad($right_text, $cols_width, ' ', STR_PAD_LEFT);
    }

    public function getPrintableSummary($label, $value, $is_double_width = false, $format = false)
    {
        $left_cols = $is_double_width ? 5 : 10;
        $right_cols = $is_double_width ? 10 : 15;

        if ($format) {
            $formatted_value = $this->currency . number_format($value, 2, '.', '');
        } else {
            $formatted_value = $value;
        }

        return str_pad($label, $left_cols) . str_pad($formatted_value, $right_cols, ' ', STR_PAD_LEFT);
    }

    public function feed($feed = NULL)
    {
        $this->printer->feed($feed);
    }

    public function cut()
    {
        $this->printer->cut();
    }

    public function printDashedLine()
    {
        $line = '';

        for ($i = 0; $i < 32; $i++) {
            $line .= '-';
        }

        $this->printer->text($line);
    }

    public function printLogo()
    {
        if ($this->logo) {
            $image = EscposImage::load($this->logo, false);

            //$this->printer->feed();
            //$this->printer->bitImage($image);
            //$this->printer->feed();
        }
    }

    public function printQRcode()
    {
        if (!empty($this->qr_code)) {
            $this->printer->qrCode($this->getPrintableQRcode(), Printer::QR_ECLEVEL_L, 8);
        }
    }

    public function printReceipt1($with_items = true)
    {
        if ($this->printer) {
            // Get total, subtotal, etc
            $subtotal = $this->getPrintableSummary('Subtotal', $this->subtotal);
            $tax = $this->getPrintableSummary('Tax', $this->tax);
            $total = $this->getPrintableSummary('TOTAL', $this->grandtotal, true);
            $header = $this->getPrintableHeader(
                'OID:' . $this->transaction_id,
                'MID:' . $this->store->getMID()
            );
            $footer = "Thank you for shopping with us on " . $this->store->getWebsite() . "\n";
            // Init printer settings
            $this->printer->initialize();
            $this->printer->selectPrintMode();
            // Set margins
            $this->printer->setPrintLeftMargin(1);
            // Print receipt headers
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            // Print logo
            $this->printLogo();
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->feed(2);
            $this->printer->text("{$this->store->getName()}\n");
            $this->printer->selectPrintMode();
            $this->printer->text("{$this->store->getAddress()}\n");
            $this->printer->text("Ph: {$this->store->getPhone()}\n");
            $this->printer->text("Email: {$this->store->getEmail()}\n");
            $this->printer->text("\n" . $header . "\n");
            $this->printer->feed();
            // Print receipt title
            $this->printer->setEmphasis(true);
            $this->printer->text("RECEIPT\n");
            $this->printer->setEmphasis(false);
            $this->printer->feed();
            // Print items
            if ($with_items) {
                $this->printer->setJustification(Printer::JUSTIFY_LEFT);
                foreach ($this->items as $item) {
                    $this->printer->text($item);
                }
                $this->printer->feed();
            }
            // Print subtotal
            $this->printer->setEmphasis(true);
            $this->printer->text($subtotal);
            $this->printer->setEmphasis(false);
            $this->printer->feed();
            // Print tax
            $this->printer->text($tax);
            $this->printer->feed(2);
            // Print grand total
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text($total);
            $this->printer->feed();
            $this->printer->selectPrintMode();
            // Print qr code
            $this->printQRcode();
            // Print receipt footer
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text($footer);
            $this->printer->feed();
            // Print receipt date
            $this->printer->text(date('j F Y H:i:s') . "\n\n");
            $this->printer->feed(2);
            // Cut the receipt
            $this->printer->cut();
            $this->printer->close();
        } else {
            throw new Exception('Printer has not been initialized.');
        }
    }

    public function printReceipt($with_items = true)
    {
        if ($this->printer) {
            $total = $this->getPrintableSummary('TOTAL', $this->total, true, true);
            // Init printer settings
            $this->printer->initialize();
            $this->printer->selectPrintMode();
            // Set margins
            $this->printer->setPrintLeftMargin(1);
            // Print receipt headers
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            // Print logo
            $this->printer->text("{$this->order_id}\n");
            $this->printer->feed();
            $this->printer->text("{$this->delivery_method}\n");
            $this->printer->feed();
            $this->printer->selectPrintMode();
            $this->printer->text("{$this->restaurant_name}\n");
            $this->printer->text(date('j F Y g:i A') . "\n\n");
            $this->printer->setEmphasis(true);
            $this->printer->text("ITEMS\n");
            $this->printer->setEmphasis(false);
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text(addSpaces('No', 5) . addSpaces('Item', 20) . addSpaces('Qty', 5) . "\n");
            if ($with_items) {
                foreach ($this->items as $item) {
                    $this->printer->text($item);
                }
                $this->printer->feed();
            }
            $this->printer->setEmphasis(true);
            $this->printer->text(addSpaces('Total Items', 20) . addSpaces($this->item_count, 10) . "\n");
            $this->printer->text(addSpaces('Total Quantity', 20) . addSpaces($this->quantity, 10) . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text("Payment {$this->payment_status}\n");
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text($total);
            $this->printer->feed();
            $this->printer->selectPrintMode();
            $this->printer->setEmphasis(true);
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("Customer\n");
            $this->printer->text(addSpaces('Name:', 10) . addSpaces($this->customer_name, 20) . "\n");
            $this->printer->text(addSpaces('Phone:', 10) . addSpaces($this->customer_mobile, 20) . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->feed();
            $this->printer->text("-------------------------------\n");
            $this->printer->feed(2);
            // Cut the receipt
            $this->printer->cut();
            $this->printer->close();
        }
    }

    public function printRequest()
    {
        if ($this->printer) {
            // Get request amount
            $total = $this->getPrintableSummary('TOTAL', $this->request_amount, true);
            $header = $this->getPrintableHeader(
                'TID: ' . $this->transaction_id,
                'MID: ' . $this->store->getMID()
            );
            $footer = "This is not a proof of payment.\n";
            // Init printer settings
            $this->printer->initialize();
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            // Print logo
            $this->printLogo();
            // Print receipt headers
            //$this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            //$this->printer->text("U L T I P A Y\n");
            //$this->printer->feed();
            $this->printer->selectPrintMode();
            $this->printer->text("{$this->store->getName()}\n");
            $this->printer->text("{$this->store->getAddress()}\n");
            $this->printer->text($header . "\n");
            $this->printer->feed();
            // Print receipt title
            $this->printDashedLine();
            $this->printer->setEmphasis(true);
            $this->printer->text("PAYMENT REQUEST\n");
            $this->printer->setEmphasis(false);
            $this->printDashedLine();
            $this->printer->feed();
            // Print instruction
            $this->printer->text("Please scan the code below\nto make payment\n");
            $this->printer->feed();
            // Print qr code
            $this->printQRcode();
            $this->printer->feed();
            // Print grand total
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text($total . "\n");
            $this->printer->feed();
            $this->printer->selectPrintMode();
            // Print receipt footer
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text($footer);
            $this->printer->feed();
            // Print receipt date
            $this->printer->text(date('j F Y H:i:s'));
            $this->printer->feed(2);
            // Cut the receipt
            $this->printer->cut();
            $this->printer->close();
        } else {
            throw new Exception('Printer has not been initialized.');
        }
    }

    public function setOrderID($order_id)
    {
        $this->order_id = $order_id;
    }

    public function setRestaurantName($restaurant_name)
    {
        $this->restaurant_name = $restaurant_name;
    }
    public function setDeliveryMethod($delivery_method)
    {
        $this->delivery_method = strtoupper($delivery_method);
    }
    public function setStoreAddress($store_address)
    {
        $this->store_address = $store_address;
    }
    public function setItemCount($item_count)
    {
        $this->item_count = $item_count;
    }
    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;
    }
    public function setPaymentStatus($payment_status)
    {
        $this->payment_status = ucwords($payment_status);
    }
    public function setTotal($total)
    {
        $this->total = $total;
    }
    public function setCustomerName($customer_name)
    {
        $this->customer_name = $customer_name;
    }
    public function setCustomerMobile($customer_mobile)
    {
        $this->customer_mobile = $customer_mobile;
    }
}
