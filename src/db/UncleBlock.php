<?php

namespace p2pool\db;

class UncleBlock extends Block{
    private string $parent_id;
    private int $parent_height;


    /**
     * @param string $parent_id
     * @param int $parent_height
     * @param string $id
     * @param int $height
     * @param string $previous_id
     * @param string $coinbase_id
     * @param int $coinbase_reward
     * @param string $coinbase_privkey
     * @param string $difficulty
     * @param int $timestamp
     * @param int $miner
     * @param string $pow_hash
     * @param int $main_height
     * @param string $main_id
     * @param bool $main_found
     * @param string $miner_main_id
     * @param string $miner_main_difficulty
     */
    public function __construct(string $parent_id, int $parent_height, string $id, int $height, string $previous_id, string $coinbase_id, int $coinbase_reward, string $coinbase_privkey, string $difficulty, int $timestamp, int $miner, string $pow_hash, int $main_height, string $main_id, bool $main_found, string $miner_main_id, string $miner_main_difficulty) {
        $this->parent_id = $parent_id;
        $this->parent_height = $parent_height;
        parent::__construct($id, $height, $previous_id, $coinbase_id, $coinbase_reward, $coinbase_privkey, $difficulty, $timestamp, $miner, $pow_hash, $main_height, $main_id, $main_found, $miner_main_id, $miner_main_difficulty);
    }

    /**
     * @return string
     */
    public function getParentId(): string {
        return $this->parent_id;
    }

    /**
     * @return int
     */
    public function getParentHeight(): int {
        return $this->parent_height;
    }

}