<?php

namespace p2pool\db;

use p2pool\MoneroAddress;

class Miner{
    private int $id;
    private string $address;

    public function __construct(int $id, string $address){
        $this->id = $id;
        $this->address = $address;
    }

    public function getAddress() : string{
        return $this->address;
    }

    public function getMoneroAddress() : MoneroAddress{
        return new MoneroAddress($this->getAddress());
    }

    public function getId() : int{
        return $this->id;
    }
}