<?php

namespace p2pool\db;

class CoinbaseTransactionOutput{
    private string $id;
    private int $index;
    private int $amount;
    private int $miner;

    /**
     * @param string $id
     * @param int $index
     * @param int $amount
     * @param int $miner
     */
    public function __construct(string $id, int $index, int $amount, int $miner) {
        $this->id = $id;
        $this->index = $index;
        $this->amount = $amount;
        $this->miner = $miner;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getIndex(): int {
        return $this->index;
    }

    /**
     * @return int
     */
    public function getAmount(): int {
        return $this->amount;
    }

    /**
     * @return int
     */
    public function getMiner(): int {
        return $this->miner;
    }
}