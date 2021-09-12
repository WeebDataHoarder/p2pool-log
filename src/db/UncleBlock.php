<?php

namespace p2pool\db;

class UncleBlock{
    private string $id;
    private string $parent_id;
    private int $parent_height;

    private int $height;
    private string $previous_id;
    private int $timestamp;
    private int $miner;

    /**
     * @param string $id
     * @param string $parent_id
     * @param int $parent_height
     * @param int $height
     * @param string $previous_id
     * @param int $timestamp
     * @param int $miner
     */
    public function __construct(string $id, string $parent_id, int $parent_height, int $height, string $previous_id, int $timestamp, int $miner) {
        $this->id = $id;
        $this->parent_id = $parent_id;
        $this->parent_height = $parent_height;
        $this->height = $height;
        $this->previous_id = $previous_id;
        $this->timestamp = $timestamp;
        $this->miner = $miner;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
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
    public function getTimestamp(): int {
        return $this->timestamp;
    }

    /**
     * @return int
     */
    public function getMiner(): int {
        return $this->miner;
    }

}