<?php

namespace p2pool\db;

use p2pool\MoneroAddress;

class Subscription{
    private int $miner;
    private string $nick;

    /**
     * @param int $miner
     * @param string $nick
     */
    public function __construct(int $miner, string $nick) {
        $this->miner = $miner;
        $this->nick = $nick;
    }

    /**
     * @return int
     */
    public function getMiner(): int {
        return $this->miner;
    }

    /**
     * @return string
     */
    public function getNick(): string {
        return $this->nick;
    }


}