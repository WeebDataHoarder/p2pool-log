<?php

namespace p2pool;


use p2pool\db\Block;
use p2pool\db\Database;
use p2pool\db\UncleBlock;

class P2PoolAPI{
    private string $path;
    private Database $db;

    public function __construct(Database $database, string $path){
        $this->db = $database;
        $this->path = realpath($path);
        if(!is_dir($this->path)){
            throw new \Exception("P2PoolAPI path does not exist {$path} {$this->path}");
        }
    }

    private function getBlockPath(int $height) : string{
        $index = (string) $height;
        return $this->path . "/share/" . substr($index, -1) . "/$index";
    }

    public function blockExists(int $height) : bool{
        return file_exists($this->getBlockPath($height));
    }

    /**
     * @param int $height
     * @param UncleBlock[] $uncles
     * @return Block|null
     */
    public function getShareEntry(int $height, array &$uncles = []) : ?Block{
        return Block::fromJSONObject($this->db, json_decode(file_get_contents($this->getBlockPath($height)), false), $uncles);
    }

    public function getPoolBlocks() : ?object {
        $ob = json_decode(file_get_contents($this->path . "/pool/blocks"), false);
        return is_object($ob) ? $ob : null;
    }

    public function getPoolStats() : ?object {
        $ob = json_decode(file_get_contents($this->path . "/pool/stats"), false);
        return is_object($ob) ? $ob : null;
    }

    public function getNetworkStats() : ?object {
        $ob = json_decode(file_get_contents($this->path . "/pool/network"), false);
        return is_object($ob) ? $ob : null;
    }

    public function getDatabase() : Database {
        return $this->db;
    }
}