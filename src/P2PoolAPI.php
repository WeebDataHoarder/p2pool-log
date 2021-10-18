<?php

namespace p2pool;


use MoneroIntegrations\MoneroPhp\Cryptonote;
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

    private function getRawBlockPath(string $id) : string{
        return $this->path . "/blocks/" . $id[0] . "/$id";
    }

    private function getFailedRawBlockPath(string $id) : string{
        return $this->path . "/failed_blocks/" . $id[0] . "/$id";
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
        $ob = json_decode(file_get_contents($this->getBlockPath($height)), false);
        return is_object($ob) ? Block::fromJSONObject($this->db, $ob, $uncles) : null;
    }

    /**
     * @param string $id
     * @return Block|null
     * @throws \Exception
     */
    public function getShareFromFailedRawEntry(string $id) : ?Block{
        try{
            return Block::fromBinaryBlock($this->db, BinaryBlock::fromHexDump($this->getFailedRawBlock($id)));
        }catch (\Throwable $e){
            return null;
        }
    }

    /**
     * @param string $id
     * @param UncleBlock[] $uncles
     * @return Block|null
     * @throws \Exception
     */
    public function getShareFromRawEntry(string $id, array &$uncles = [], bool $throwOnMissingUncle = false) : ?Block{
        try{
            $raw = BinaryBlock::fromHexDump($this->getRawBlock($id));
        }catch (\Throwable $e){
            return null;
        }

        $u = [];

        foreach ($raw->getUncles() as $uncle){
            try{
                $u[] = BinaryBlock::fromHexDump($this->getRawBlock($uncle));
            }catch (\Throwable $e){
                if($throwOnMissingUncle){
                    return null;
                }
            }
        }

        return Block::fromBinaryBlock($this->db, $raw, $u, $uncles);
    }

    /**
     * @return object[]
     */
    public function getPoolBlocks() : array {
        $ob = json_decode(file_get_contents($this->path . "/pool/blocks"), false);
        return is_array($ob) ? $ob : [];
    }

    public function getPoolStats() : ?object {
        $ob = json_decode(file_get_contents($this->path . "/pool/stats"), false);
        return is_object($ob) ? $ob : null;
    }

    public function getNetworkStats() : ?object {
        $ob = json_decode(file_get_contents($this->path . "/pool/network"), false);
        return is_object($ob) ? $ob : null;
    }

    public function getRawBlock(string $id) : ?string{
        return file_exists($this->getRawBlockPath($id)) ? file_get_contents($this->getRawBlockPath($id)) : null;
    }

    public function getFailedRawBlock(string $id) : ?string{
        return file_exists($this->getFailedRawBlockPath($id)) ? file_get_contents($this->getFailedRawBlockPath($id)) : null;
    }

    public function getDatabase() : Database {
        return $this->db;
    }

    public function getWindowPayouts(int $startBlock = null, int $totalReward = null): array {
        $shares = [];

        $tip = $startBlock ?? $this->db->getChainTip()->getHeight();

        $block_count = 0;

        foreach($this->db->getBlocksInWindow($tip) as $block){
            if (!isset($shares[$block->getMiner()])) {
                $shares[$block->getMiner()] = 0;
            }
            $shares[$block->getMiner()] = gmp_add($shares[$block->getMiner()], gmp_init($block->getDifficulty(), 16));

            foreach ($this->db->getUnclesByParentId($block->getId()) as $uncle) {
                if (($tip - $uncle->getHeight()) >= SIDECHAIN_PPLNS_WINDOW) {
                    continue;
                }
                if (!isset($shares[$uncle->getMiner()])) {
                    $shares[$uncle->getMiner()] = 0;
                }

                $uncle_diff = gmp_init($uncle->getDifficulty(), 16);
                $product = gmp_mul($uncle_diff, SIDECHAIN_UNCLE_PENALTY);
                list($uncle_penalty, $rem) = gmp_div_qr($product, 100);

                $shares[$block->getMiner()] = gmp_add($shares[$block->getMiner()], $uncle_penalty);
                $shares[$uncle->getMiner()] = gmp_add($shares[$uncle->getMiner()], gmp_sub($uncle_diff, $uncle_penalty));
            }
            ++$block_count;
        }

        if($totalReward !== null){
            $total_weight = gmp_init(0);
            foreach ($shares as $r){
                $total_weight = gmp_add($total_weight, $r);
            }
            $w = gmp_init(0);
            $reward_given = gmp_init(0);
            foreach ($shares as $i => $weight){
                $w = gmp_add($w, $weight);

                $a = gmp_mul($w, $totalReward);
                list($next_value, $rem) = gmp_div_qr($a, $total_weight);
                $shares[$i] = gmp_sub($next_value, $reward_given);
                $reward_given = $next_value;
            }
        }

        return $block_count !== SIDECHAIN_PPLNS_WINDOW ? [] : array_map("gmp_intval", $shares);
    }
}