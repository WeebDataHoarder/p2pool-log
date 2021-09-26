<?php

namespace p2pool\db;

use MoneroIntegrations\MoneroPhp\Cryptonote;
use p2pool\BinaryBlock;

class Block{
    private string $id;
    private int $height;
    private string $previous_id;

    private string $coinbase_id;
    private int $coinbase_reward;
    private string $coinbase_privkey;

    private string $difficulty;
    private int $timestamp;
    private int $miner;
    private string $pow_hash;


    private int $main_height;
    private string $main_id;
    private bool $main_found = false;

    private string $miner_main_id;
    private string $miner_main_difficulty;

    /**
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
    public function __construct(string $id, int $height, string $previous_id, string $coinbase_id, int $coinbase_reward, string $coinbase_privkey, string $difficulty, int $timestamp, int $miner, string $pow_hash, int $main_height, string $main_id, bool $main_found, string $miner_main_id, string $miner_main_difficulty) {
        $this->id = $id;
        $this->height = $height;
        $this->previous_id = $previous_id;
        $this->coinbase_id = $coinbase_id;
        $this->coinbase_reward = $coinbase_reward;
        $this->coinbase_privkey = $coinbase_privkey;
        $this->difficulty = $difficulty;
        $this->timestamp = $timestamp;
        $this->miner = $miner;
        $this->pow_hash = $pow_hash;
        $this->main_height = $main_height;
        $this->main_id = $main_id;
        $this->main_found = $main_found;
        $this->miner_main_id = $miner_main_id;
        $this->miner_main_difficulty = $miner_main_difficulty;
    }

    /**
     * @param Database $database
     * @param BinaryBlock $b
     * @param BinaryBlock[] $knownUncles
     * @param array $uncles
     * @return Block|null
     * @throws \Exception
     */
    public static function fromBinaryBlock(Database $database, BinaryBlock $b, $knownUncles = [], array &$uncles = []) : Block{
        $miner = $database->getOrCreateMinerByAddress($b->getPublicAddress());
        if($miner === null){
            throw new \Exception("Could not get or create miner");
        }

        $block = new Block(
            $b->getId(), $b->getHeight(), $b->getParent(),
            $b->getCoinbaseTxId(), $b->getCoinbaseTxReward(), $b->getCoinbaseTxPrivateKey(),
            $b->getDifficulty(), $b->getTimestamp(), $miner->getId(),
            $b->getExtra()->powHash ?? str_repeat("00", 32),
            $b->getCoinbaseTxGenHeight(),
            $b->getExtra()->mainId ?? str_repeat("00", 32),
            false,
            str_repeat("ff", 32),
            $b->getExtra()->mainDifficulty ?? str_repeat("ff", 32)
        );
        $block->main_found = $block->isProofHigherThanDifficulty();
        
        foreach ($b->getUncles() as $u){
            foreach ($knownUncles as $uncle){
                if($u === $uncle->getId()){
                    $uncle_block = new UncleBlock(
                        $block->getId(), $block->getHeight(),
                        $uncle->getId(), $uncle->getHeight(), $uncle->getParent(),
                        $uncle->getCoinbaseTxId(), $uncle->getCoinbaseTxReward(), $uncle->getCoinbaseTxPrivateKey(),
                        $uncle->getDifficulty(), $uncle->getTimestamp(), $miner->getId(),
                        $uncle->getExtra()->powHash ?? str_repeat("00", 32),
                        $uncle->getCoinbaseTxGenHeight(),
                        $uncle->getExtra()->mainId ?? str_repeat("00", 32),
                        false,
                        str_repeat("ff", 32),
                        $uncle->getExtra()->mainDifficulty ?? str_repeat("ff", 32)
                    );
                    $uncle_block->main_found = $uncle_block->isProofHigherThanDifficulty();
                    $uncles[] = $uncle_block;
                    break;
                }
            }
        }

        /*
        if(count($uncles) !== count($b->getUncles())){
            throw new \Exception("Could not find all uncles")
        }
        */

        return $block;
    }


    public static function fromJSONObject(Database $database, object $ob, array &$uncles = []) : ?Block{
        if(isset($ob->version) and $ob->version === "2"){
            $miner = $database->getOrCreateMinerByAddress($ob->wallet);
            if($miner === null){
                throw new \Exception("Could not get or create miner");
            }
            $block = new Block($ob->id, (int) $ob->height, $ob->prev_id, $ob->coinbase_id, (int) $ob->coinbase_reward, $ob->coinbase_priv, $ob->diff, (int) $ob->ts, $miner->getId(), $ob->pow_hash, (int) $ob->main_height, $ob->main_id, (isset($ob->main_found) and $ob->main_found === "true"), $ob->miner_main_id, $ob->miner_main_diff);
            if($block->isProofHigherThanDifficulty()){
                $block->main_found = true;
            }
            
            if(isset($ob->uncles)){
                foreach($ob->uncles as $u){
                    if(!isset($u->wallet)){
                        //Unknown block, just have hash
                        continue;
                    }
                    $miner = $database->getOrCreateMinerByAddress($u->wallet);
                    if($miner === null){
                        throw new \Exception("Could not get or create miner");
                    }

                    $uncle = new UncleBlock($block->getId(), $block->getHeight(), $u->id, (int) $u->height, $u->prev_id, $u->coinbase_id, (int) $u->coinbase_reward, $u->coinbase_priv, $u->diff, (int) $u->ts, $miner->getId(), $u->pow_hash, (int) $u->main_height, $u->main_id, (isset($u->main_found) and $u->main_found === "true"), $u->miner_main_id, $u->miner_main_diff);
                    if($uncle->isProofHigherThanDifficulty()){
                        $uncle->main_found = true;
                    }
                    $uncles[] = $uncle;
                }
            }

            return $block;
        }else{
            //Old disk format
            $miner = $database->getOrCreateMinerByAddress($ob->wallet);
            if($miner === null){
                throw new \Exception("Could not get or create miner");
            }
            $block = new Block($ob->id, (int) $ob->height, $ob->prev_hash, $ob->tx_coinbase, 0, $ob->tx_priv, str_pad(gmp_strval(gmp_init($ob->diff), 16), 32, "0", STR_PAD_LEFT), (int) $ob->ts, $miner->getId(), $ob->pow_hash, (int) $ob->mheight, $ob->mhash, (isset($ob->block_found) and $ob->block_found === "true"), str_repeat("ff", 32), str_repeat("ff", 16));
            if($block->isProofHigherThanDifficulty()){
                $block->main_found = true;
            }

            if(isset($ob->uncles)){
                foreach($ob->uncles as $u){
                    if(!isset($u->wallet)){
                        //Unknown block, just have hash
                        continue;
                    }
                    $miner = $database->getOrCreateMinerByAddress($u->wallet);
                    if($miner === null){
                        throw new \Exception("Could not get or create miner");
                    }

                    $uncle = new UncleBlock($block->getId(), $block->getHeight(), $u->id, (int) $u->height, $u->prev_hash, str_repeat("00", 16), 0, str_repeat("00", 32), str_pad(gmp_strval(gmp_init($u->diff), 16), 32, "0", STR_PAD_LEFT), (int) $u->ts, $miner->getId(), str_repeat("00", 32), 0, str_repeat("00", 32), false, str_repeat("ff", 32), str_repeat("ff", 16));
                    if($uncle->isProofHigherThanDifficulty()){
                        $uncle->main_found = true;
                    }
                    $uncles[] = $uncle;
                }
            }

            return $block;
        }
        return null;
    }

    public function getProofDifficulty() : string {
        $base = gmp_sub(gmp_pow(2, 256), 1);
        $pow = gmp_init(implode(array_reverse(str_split($this->getPowHash(), 2))), 16); //Need to reverse it
        return str_pad(gmp_cmp(0, $pow) == 0 /* Unknown PoW */ ? "" : gmp_strval(gmp_div($base, $pow), 16), strlen($this->getPowHash()), "0", STR_PAD_LEFT);
    }

    public function isProofHigherThanDifficulty() : bool {
        return gmp_cmp(gmp_init($this->getProofDifficulty(), 16), gmp_init($this->getMinerMainDifficulty(), 16)) >= 0;
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
     * @return string
     */
    public function getCoinbaseId(): string {
        return $this->coinbase_id;
    }

    /**
     * @return int
     */
    public function getCoinbaseReward(): int {
        return $this->coinbase_reward;
    }

    /**
     * @return string
     */
    public function getCoinbasePrivkey(): string {
        return $this->coinbase_privkey;
    }

    /**
     * @return string
     */
    public function getDifficulty(): string {
        return $this->difficulty;
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
    public function getPowHash(): string {
        return $this->pow_hash;
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
    public function getMainId(): string {
        return $this->main_id;
    }

    /**
     * @return bool
     */
    public function isMainFound(): bool {
        return $this->main_found;
    }

    /**
     * @return string
     */
    public function getMinerMainId(): string {
        return $this->miner_main_id;
    }

    /**
     * @return string
     */
    public function getMinerMainDifficulty(): string {
        return $this->miner_main_difficulty;
    }



}