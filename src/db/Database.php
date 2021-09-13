<?php

namespace p2pool\db;

use const p2pool\SIDECHAIN_PPLNS_WINDOW;

class Database{
    private $db;
    public function __construct($pgconnect_url){
        if(($this->db = \pg_connect($pgconnect_url)) === false){
            throw new \Error("Could not connect to postgres");
        }
    }

    private function reconnect(){
        if(\pg_connection_status($this->db) !== PGSQL_CONNECTION_OK){
            if(!\pg_connection_reset($this->db)){
                throw new \Error("Could not reconnect to postgres");
            }
        }
    }

    public function removeSubscription(Subscription $sub){
        pg_query_params($this->db, 'DELETE FROM subscriptions WHERE miner = $1 AND nick = $2;', [$sub->getMiner(), $sub->getNick()]);
    }

    public function addSubscription(Subscription $sub){
        pg_query_params($this->db, 'INSERT INTO subscriptions (miner, nick) VALUES ($1, $2);', [$sub->getMiner(), $sub->getNick()]);
    }

    /**
     * @param string $nick
     * @return \Iterator|Subscription[]
     */
    public function getSubscriptionsFromNick(string $nick) : \Iterator{
        $result = pg_query_params($this->db, 'SELECT miner, nick FROM subscriptions WHERE nick = $1;', [$nick]);
        while(($res = pg_fetch_assoc($result)) !== false){
            yield new Subscription($res["miner"], $res["nick"]);
        }
    }

    /**
     * @param int $miner
     * @return \Iterator|Subscription[]
     */
    public function getSubscriptionsFromMiner(int $miner) : \Iterator{
        $result = pg_query_params($this->db, 'SELECT miner, nick FROM subscriptions WHERE miner = $1;', [$miner]);
        while(($res = pg_fetch_assoc($result)) !== false){
            yield new Subscription($res["miner"], $res["nick"]);
        }
    }

    public function getMiner(int $id) : ?Miner {
        $result = pg_query_params($this->db, 'SELECT id, address FROM miners WHERE id = $1;', [$id]);
        if(($res = pg_fetch_assoc($result)) === false){
            return null;
        }

        return new Miner($res["id"], $res["address"]);
    }

    public function getMinerByAddress(string $address) : ?Miner {
        $result = pg_query_params($this->db, 'SELECT id, address FROM miners WHERE address = $1;', [$address]);
        if(($res = pg_fetch_assoc($result)) === false){
            return null;
        }

        return new Miner($res["id"], $res["address"]);
    }

    public function getOrCreateMinerByAddress(string $address) : ?Miner {
        $record = $this->getMinerByAddress($address);

        if($record === null){
            $result = pg_query_params($this->db, 'INSERT INTO miners (address) VALUES ($1) RETURNING id, address;', [$address]);
            if(($res = pg_fetch_assoc($result)) === false){
                return null;
            }

            $record = new Miner($res["id"], $res["address"]);
        }

        return $record;
    }

    /**
     * @param string $where
     * @param array $params
     * @return \Generator|Block[]
     */
    private function getBlocksByQuery(string $where, array $params = []) : \Generator {
        $result = pg_query_params($this->db, 'SELECT * FROM blocks '.$where.';', $params);

        while(($res = pg_fetch_assoc($result)) !== false){
            yield new Block($res["id"], $res["height"], $res["previous_id"], $res["main_height"], $res["main_hash"], $res["difficulty"], $res["pow_hash"], $res["timestamp"], $res["miner"], $res["tx_id"], $res["tx_privkey"], $res["main_found"] === "t");
        }
    }

    /**
     * @param string $where
     * @param array $params
     * @return \Iterator|UncleBlock[]
     */
    private function getUncleBlocksByQuery(string $where, array $params = []) : \Iterator {
        $result = pg_query_params($this->db, 'SELECT * FROM uncles '.$where.';', $params);

        while(($res = pg_fetch_assoc($result)) !== false){
            yield new UncleBlock($res["id"], $res["parent_id"], $res["parent_height"], $res["height"], $res["previous_id"], $res["timestamp"], $res["miner"]);
        }
    }

    /**
     * Deletes a block and all its following blocks. Returns number of deleted blocks.
     * @param string $id
     * @return int
     */
    public function deleteBlockById(string $id){
        $deleted = 0;
        $block = $this->getBlockById($id);
        if($block === null){
            return $deleted;
        }

        do{
            pg_query_params($this->db, "DELETE FROM uncles WHERE parent_id = $1;", [$block->getId()]);
            pg_query_params($this->db, "DELETE FROM blocks WHERE id = $1;", [$block->getId()]);
            $block = $this->getBlockByPreviousId($block->getId());
            ++$deleted;
        }while($block !== null);

        return $deleted;
    }

    public function insertBlock(Block $b): bool {
        $block = $this->getBlockById($b->getId());
        if($block !== null){ //Update found status if existent
            if($b->isMainFound() and !$block->isMainFound()){
                pg_query_params($this->db, "UPDATE blocks SET main_found = 'y' WHERE id = $1;", [$block->getId()]);
            }
            return true;
        }

        return pg_query_params($this->db, "INSERT INTO blocks (id, height, previous_id, main_height, main_hash, difficulty, pow_hash, timestamp, miner, tx_id, tx_privkey, main_found) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)", [
           $b->getId(), $b->getHeight(), $b->getPreviousId(), $b->getMainHeight(), $b->getMainHash(), $b->getDifficulty(), $b->getPowHash(), $b->getTimestamp(), $b->getMiner(), $b->getTxId(), $b->getTxPrivkey(), $b->isMainFound() ? "y" : "n"
        ]) !== false;
    }

    public function insertUncleBlock(UncleBlock $u): bool {
        $block = $this->getBlockById($u->getParentId());
        if($block === null){
            return false;
        }
        $uncle = $this->getUncleById($u->getId());
        if($uncle !== null){
            return true;
        }

        return pg_query_params($this->db, "INSERT INTO uncles (id, parent_id, parent_height, height, previous_id, timestamp, miner) VALUES ($1, $2, $3, $4, $5, $6, $7)", [
                $u->getId(), $u->getParentId(), $u->getParentHeight(), $u->getHeight(), $u->getPreviousId(), $u->getTimestamp(), $u->getMiner()
            ]) !== false;
    }

    public function getChainTip() : ?Block {
        return iterator_to_array($this->getBlocksByQuery("WHERE height = (SELECT MAX(height) FROM blocks)"))[0] ?? null;
    }

    public function getLastFound() : ?Block {
        return iterator_to_array($this->getFound())[0] ?? null;
    }

    /**
     * @return \Iterator|Block[]
     */
    public function getFound(int $limit = null) : \Iterator {
        return $this->getBlocksByQuery("WHERE main_found = 'y' ORDER BY main_height DESC" . ($limit !== null ? " LIMIT $limit" : ""));
    }

    public function getBlockById(string $id) : ?Block {
        return iterator_to_array($this->getBlocksByQuery('WHERE id = $1', [$id]))[0] ?? null;
    }

    public function getBlockByPreviousId(string $id) : ?Block {
        return iterator_to_array($this->getBlocksByQuery('WHERE previous_id = $1', [$id]))[0] ?? null;
    }

    public function getBlockByHeight(int $height) : ?Block {
        return iterator_to_array($this->getBlocksByQuery('WHERE height = $1', [$height]))[0] ?? null;
    }

    /**
     * @param int $miner
     * @return \Iterator|Block[]
     */
    public function getBlocksByMinerId(int $miner) : \Iterator {
        return $this->getBlocksByQuery('WHERE miner = $1', [$miner]);
    }

    /**
     * @param int|null $fromBlock
     * @return \Iterator|Block[]
     */
    public function getBlocksInWindow(int $fromBlock = null) : \Iterator {
        $tip = $this->getChainTip();
        return $this->getBlocksByQuery('WHERE height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').')', [SIDECHAIN_PPLNS_WINDOW]);
    }

    /**
     * @param int $miner
     * @param int|null $fromBlock
     * @return \Iterator|Block[]
     */
    public function getBlocksByMinerIdInWindow(int $miner, int $fromBlock = null) : \Iterator {
        $tip = $this->getChainTip();
        return $this->getBlocksByQuery('WHERE height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND miner = $2', [SIDECHAIN_PPLNS_WINDOW, $miner]);
    }

    public function getUncleById(string $id){
        return iterator_to_array($this->getUncleBlocksByQuery('WHERE id = $1', [$id]))[0] ?? null;
    }

    /**
     * @param string $id
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesByParentId(string $id) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_id = $1', [$id]);
    }

    /**
     * @param int $height
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesByParentHeight(int $height) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_height = $1', [$height]);
    }

    /**
     * @param int|null $fromBlock
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesInWindow(int $fromBlock = null) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').')', [SIDECHAIN_PPLNS_WINDOW]);
    }

    /**
     * @param int $miner
     * @param int|null $fromBlock
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesByMinerIdInWindow(int $miner, int $fromBlock = null) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND miner = $2', [SIDECHAIN_PPLNS_WINDOW, $miner]);
    }
}

