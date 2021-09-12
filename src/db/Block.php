<?php

namespace p2pool\db;

class Block{
    private string $id;
    private int $height;

    private string $previous_id;
    private int $main_height;
    private string $main_hash;
    private bool $main_found = false;
    private int $difficulty;
    private string $pow_hash;
    private int $timestamp;
    private int $miner;
    private string $tx_id;
    private string $tx_privkey;

    /**
     * @param string $id
     * @param int $height
     * @param string $previous_id
     * @param int $main_height
     * @param string $main_hash
     * @param int $difficulty
     * @param string $pow_hash
     * @param int $timestamp
     * @param int $miner
     * @param string $tx_id
     * @param string $tx_privkey
     * @param bool $main_found
     */
    public function __construct(string $id, int $height, string $previous_id, int $main_height, string $main_hash, int $difficulty, string $pow_hash, int $timestamp, int $miner, string $tx_id, string $tx_privkey, bool $main_found = false) {
        $this->id = $id;
        $this->height = $height;
        $this->previous_id = $previous_id;
        $this->main_height = $main_height;
        $this->main_hash = $main_hash;
        $this->difficulty = $difficulty;
        $this->pow_hash = $pow_hash;
        $this->timestamp = $timestamp;
        $this->miner = $miner;
        $this->tx_id = $tx_id;
        $this->tx_privkey = $tx_privkey;
        $this->main_found = $main_found;
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
    public function getHeight(): int {
        return $this->height;
    }

    /**
     * @return string
     */
    public function getPreviousId(): string {
        return $this->previous_id;
    }

    /**
     * @return int
     */
    public function getMainHeight(): int {
        return $this->main_height;
    }

    /**
     * @return string
     */
    public function getMainHash(): string {
        return $this->main_hash;
    }

    /**
     * @return bool
     */
    public function isMainFound(): bool {
        return $this->main_found;
    }

    /**
     * @return int
     */
    public function getDifficulty(): int {
        return $this->difficulty;
    }

    /**
     * @return string
     */
    public function getPowHash(): string {
        return $this->pow_hash;
    }

    /**
     * @return int
     */
    public function getTimestamp(): int {
        return $this->timestamp;
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
    public function getTxId(): string {
        return $this->tx_id;
    }

    /**
     * @return string
     */
    public function getTxPrivkey(): string {
        return $this->tx_privkey;
    }

}