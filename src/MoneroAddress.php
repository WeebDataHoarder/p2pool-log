<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\base58;
use MoneroIntegrations\MoneroPhp\Cryptonote;

class MoneroAddress{

    private string $view_pub;
    private string $spend_pub;
    private int $net;
    private string $checksum;

    public function __construct(string $address){
        $b58 = new base58();
        $b = $b58->decode($address);

        $offset = 0;
        $this->net = hexdec(substr($b, $offset, 2));
        $offset += 2;

        $this->spend_pub = substr($b, $offset, 64);
        $offset += 64;

        $this->view_pub = substr($b, $offset, 64);
        $offset += 64;

        $this->checksum = substr($b, $offset);
    }

    public function getAddress() : string {
        $b58 = new base58();
        return $b58->encode(dechex($this->net) . $this->spend_pub . $this->view_pub . $this->checksum);
    }

    public function getSpendPub() : string {
        return $this->spend_pub;
    }

    public function getViewPub() : string {
        return $this->view_pub;
    }

    public function getChecksum() : string {
        return $this->checksum;
    }

    public function getNetwork() : int {
        return $this->net;
    }

    public function verify() : bool{
        $cn = new Cryptonote();
        return $cn->verify_checksum($this->getAddress());
    }
}
