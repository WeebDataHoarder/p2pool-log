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
        while($result !== false and ($res = pg_fetch_assoc($result)) !== false){
            yield new Subscription($res["miner"], $res["nick"]);
        }
    }

    /**
     * @param int $miner
     * @return \Iterator|Subscription[]
     */
    public function getSubscriptionsFromMiner(int $miner) : \Iterator{
        $result = pg_query_params($this->db, 'SELECT miner, nick FROM subscriptions WHERE miner = $1;', [$miner]);
        while($result !== false and ($res = pg_fetch_assoc($result)) !== false){
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

    public function getMinerByAddressBounds(string $addressStart, string $addressEnd) : ?Miner {
        $result = pg_query_params($this->db, 'SELECT id, address FROM miners WHERE address LIKE $1 AND address LIKE $2;', [$addressStart . "%", "%" . $addressEnd]);
        if(($res = pg_fetch_assoc($result)) === false){
            return null;
        }

        return new Miner($res["id"], $res["address"]);
    }

    public function query(string $query, array $params = []) : \Iterator{
        $result = pg_query_params($this->db, $query, $params);
        while($result !== false and ($res = pg_fetch_assoc($result)) !== false){
            yield $res;
        }
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
    public function getBlocksByQuery(string $where, array $params = []) : \Generator {
        $result = pg_query_params($this->db, 'SELECT * FROM blocks '.$where.';', $params);

        while($result !== false and ($res = pg_fetch_assoc($result)) !== false){
            yield new Block($res["id"], $res["height"], $res["previous_id"], $res["coinbase_id"], $res["coinbase_reward"], $res["coinbase_privkey"], $res["difficulty"], $res["timestamp"], $res["miner"], $res["pow_hash"], $res["main_height"], $res["main_id"], $res["main_found"] === "t", $res["miner_main_id"], $res["miner_main_difficulty"]);
        }
    }

    /**
     * @param string $where
     * @param array $params
     * @return \Iterator|UncleBlock[]
     */
    public function getUncleBlocksByQuery(string $where, array $params = []) : \Iterator {
        $result = pg_query_params($this->db, 'SELECT * FROM uncles '.$where.';', $params);

        while($result !== false and ($res = pg_fetch_assoc($result)) !== false){
            yield new UncleBlock($res["parent_id"], $res["parent_height"], $res["id"], $res["height"], $res["previous_id"], $res["coinbase_id"], $res["coinbase_reward"], $res["coinbase_privkey"], $res["difficulty"], $res["timestamp"], $res["miner"], $res["pow_hash"], $res["main_height"], $res["main_id"], $res["main_found"] === "t", $res["miner_main_id"], $res["miner_main_difficulty"]);
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
            pg_query_params($this->db, "DELETE FROM coinbase_outputs WHERE id = (SELECT coinbase_id FROM blocks WHERE id = $1) OR id = (SELECT coinbase_id FROM uncles WHERE id = $1);", [$block->getId()]);
            pg_query_params($this->db, "DELETE FROM uncles WHERE parent_id = $1;", [$block->getId()]);
            pg_query_params($this->db, "DELETE FROM blocks WHERE id = $1;", [$block->getId()]);
            $block = $this->getBlockByPreviousId($block->getId());
            ++$deleted;
        }while($block !== null);

        return $deleted;
    }

    public function setBlockFound(string $id, bool $found = true) {
        pg_query_params($this->db, "UPDATE blocks SET main_found = $2 WHERE id = $1;", [$id, $found ? 'y' : 'n']);
        pg_query_params($this->db, "UPDATE uncles SET main_found = $2 WHERE id = $1;", [$id, $found ? 'y' : 'n']);
    }

    /**
     * @param Block $block
     * @return bool
     */
    public function coinbaseTransactionExists(Block $block): bool{
        return iterator_to_array($this->query("SELECT COUNT(*) as count FROM coinbase_outputs WHERE id = $1;", [$block->getCoinbaseId()]))[0]["count"] > 0;
    }

    /**
     * @param Block $block
     * @return CoinbaseTransaction|null
     */
    public function getCoinbaseTransaction(Block $block): ?CoinbaseTransaction{
        $outputs = [];
        foreach ($this->query("SELECT * FROM coinbase_outputs WHERE id = $1;", [$block->getCoinbaseId()]) as $output){
            $outputs[(int) $output["index"]] = new CoinbaseTransactionOutput($output["id"], $output["index"], $output["amount"], $output["miner"]);
        }

        return count($outputs) > 0 ? new CoinbaseTransaction($block->getCoinbaseId(), $block->getCoinbasePrivkey(), $outputs) : null;
    }

    /**
     * @param string $id
     * @param int $index
     * @return CoinbaseTransactionOutput|null
     */
    public function getCoinbaseTransactionOutputByIndex(string $id, int $index): ?CoinbaseTransactionOutput{
        $result = iterator_to_array($this->query("SELECT * FROM coinbase_outputs WHERE id = $1 AND index = $2;", [$id, $index]));
        if(count($result) > 0){
            $output = $result[0];
            return new CoinbaseTransactionOutput($output["id"], $output["index"], $output["amount"], $output["miner"]);
        }
        return null;
    }

    /**
     * @param string $id
     * @param int $miner
     * @return CoinbaseTransactionOutput|null
     */
    public function getCoinbaseTransactionOutputByMinerId(string $id, int $miner): ?CoinbaseTransactionOutput{
        $result = iterator_to_array($this->query("SELECT * FROM coinbase_outputs WHERE id = $1 AND miner = $2;", [$id, $miner]));
        if(count($result) > 0){
            $output = $result[0];
            return new CoinbaseTransactionOutput($output["id"], $output["index"], $output["amount"], $output["miner"]);
        }
        return null;
    }

    public function insertCoinbaseTransaction(CoinbaseTransaction $tx): bool{
        pg_query($this->db, "BEGIN;");

        $success = true;
        foreach ($tx->getOutputs() as $o){
            $success = $this->insertCoinbaseTransactionOutput($o);
            if(!$success){
                break;
            }
        }

        if($success){
            pg_query($this->db, "COMMIT;");
        }else{
            pg_query($this->db, "ROLLBACK;");
        }

        return $success;
    }

    public function insertCoinbaseTransactionOutput(CoinbaseTransactionOutput $output): bool{
        $o = $this->getCoinbaseTransactionOutputByIndex($output->getId(), $output->getIndex());
        if($o !== null){
            return false;
        }

        return pg_query_params($this->db, "INSERT INTO coinbase_outputs (id, index, miner, amount) VALUES ($1, $2, $3, $4);", [$output->getId(), $output->getIndex(), $output->getMiner(), $output->getAmount()]) !== false;
    }

    public function insertBlock(Block $b): bool {
        $block = $this->getBlockById($b->getId());
        if($block !== null){ //Update found status if existent
            if($b->isMainFound() and !$block->isMainFound()){
                $this->setBlockFound($block->getId(), true);
            }
            return true;
        }

        return pg_query_params($this->db, "INSERT INTO blocks (id, height, previous_id, coinbase_id, coinbase_reward, coinbase_privkey, difficulty, timestamp, miner, pow_hash, main_height, main_id, main_found, miner_main_id, miner_main_difficulty) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)", [
           $b->getId(), $b->getHeight(), $b->getPreviousId(), $b->getCoinbaseId(), $b->getCoinbaseReward(), $b->getCoinbasePrivkey(), $b->getDifficulty(), $b->getTimestamp(), $b->getMiner(), $b->getPowHash(), $b->getMainHeight(), $b->getMainId(), $b->isMainFound() ? "y" : "n", $b->getMinerMainId(), $b->getMinerMainDifficulty()
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

        return pg_query_params($this->db, "INSERT INTO uncles (parent_id, parent_height, id, height, previous_id, coinbase_id, coinbase_reward, coinbase_privkey, difficulty, timestamp, miner, pow_hash, main_height, main_id, main_found, miner_main_id, miner_main_difficulty) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17)", [
                $u->getParentId(), $u->getParentHeight(), $u->getId(), $u->getHeight(), $u->getPreviousId(), $u->getCoinbaseId(), $u->getCoinbaseReward(), $u->getCoinbasePrivkey(), $u->getDifficulty(), $u->getTimestamp(), $u->getMiner(), $u->getPowHash(), $u->getMainHeight(), $u->getMainId(), $u->isMainFound() ? "y" : "n", $u->getMinerMainId(), $u->getMinerMainDifficulty()
            ]) !== false;
    }

    public function getChainTip() : ?Block {
        return iterator_to_array($this->getBlocksByQuery("WHERE height = (SELECT MAX(height) FROM blocks)"))[0] ?? null;
    }

    public function getLastFound() : ?Block {
        return iterator_to_array($this->getAllFound(1))[0] ?? null;
    }


    /**
     * @param int $limit
     * @param int|null $minerId
     * @return \Iterator
     */
    public function getShares(int $limit = 50, int $minerId = null) : \Iterator {
        $blocks = $this->getBlocksByQuery(($minerId !== null ? "WHERE miner = $2 " : "") . "ORDER BY height DESC, timestamp DESC LIMIT $1", [$limit, $minerId]);
        $uncles = $this->getUncleBlocksByQuery(($minerId !== null ? "WHERE miner = $2 " : "") . "ORDER BY height DESC, timestamp DESC LIMIT $1", [$limit, $minerId]);

        for($i = 0; $limit === null or $i < $limit; ++$i){
            /** @var Block $current */
            $current = null;

            if($blocks->current() !== null){
                if($current === null or $blocks->current()->getHeight() > $current->getHeight()){
                    $current = $blocks->current();
                }
            }
            if($uncles->current() !== null){
                if($current === null or $uncles->current()->getHeight() > $current->getHeight()){
                    $current = $uncles->current();
                }
            }

            if($current === null){
                break;
            }

            if($blocks->current() === $current){
                $blocks->next();
            }else if($uncles->current() === $current){
                $uncles->next();
            }

            yield $current;
        }
    }


    /**
     * @param int|null $limit
     * @return \Iterator
     */
    public function getAllFound(int $limit = null) : \Iterator {
        $blocks = $this->getFound($limit);
        $uncles = $this->getFoundUncles($limit);

        for($i = 0; $limit === null or $i < $limit; ++$i){
            /** @var Block $current */
            $current = null;

            if($blocks->current() !== null){
                if($current === null or $blocks->current()->getMainHeight() > $current->getMainHeight()){
                    $current = $blocks->current();
                }
            }
            if($uncles->current() !== null){
                if($current === null or $uncles->current()->getMainHeight() > $current->getMainHeight()){
                    $current = $uncles->current();
                }
            }

            if($current === null){
                break;
            }

            if($blocks->current() === $current){
                $blocks->next();
            }else if($uncles->current() === $current){
                $uncles->next();
            }

            yield $current;
        }
    }

    /**
     * @return \Iterator|Block[]
     */
    public function getFound(int $limit = null) : \Iterator {
        return $this->getBlocksByQuery("WHERE main_found = 'y' ORDER BY main_height DESC" . ($limit !== null ? " LIMIT $limit" : ""));
    }

    /**
     * @return \Iterator|UncleBlock[]
     */
    public function getFoundUncles(int $limit = null) : \Iterator {
        return $this->getUncleBlocksByQuery("WHERE main_found = 'y' ORDER BY main_height DESC" . ($limit !== null ? " LIMIT $limit" : ""));
    }

    /**
     * @param string $id
     * @return Block|null
     */
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
     * @param int $WINDOW_SIZE
     * @return \Iterator|Block[]
     */
    public function getBlocksInWindow(int $fromBlock = null, int $WINDOW_SIZE = SIDECHAIN_PPLNS_WINDOW) : \Iterator {
        return $this->getBlocksByQuery('WHERE height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') ORDER BY height DESC', [$WINDOW_SIZE]);
    }

    /**
     * @param int $miner
     * @param int|null $fromBlock
     * @param int $WINDOW_SIZE
     * @return \Iterator|Block[]
     */
    public function getBlocksByMinerIdInWindow(int $miner, int $fromBlock = null, int $WINDOW_SIZE = SIDECHAIN_PPLNS_WINDOW) : \Iterator {
        return $this->getBlocksByQuery('WHERE height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND miner = $2 ORDER BY height DESC', [$WINDOW_SIZE, $miner]);
    }

    /**
     * @param string $id
     * @return UncleBlock
     */
    public function getUncleById(string $id) : ?UncleBlock {
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
     * @param int $WINDOW_SIZE
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesInWindow(int $fromBlock = null, int $WINDOW_SIZE = SIDECHAIN_PPLNS_WINDOW) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND parent_height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') ORDER BY height DESC', [$WINDOW_SIZE]);
    }

    /**
     * @param int $miner
     * @param int|null $fromBlock
     * @param int $WINDOW_SIZE
     * @return \Iterator|UncleBlock[]
     */
    public function getUnclesByMinerIdInWindow(int $miner, int $fromBlock = null, int $WINDOW_SIZE = SIDECHAIN_PPLNS_WINDOW) : \Iterator {
        return $this->getUncleBlocksByQuery('WHERE parent_height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND parent_height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND height > ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').' - $1) AND height <= ('.($fromBlock !== null ? $fromBlock : '(SELECT MAX(height) FROM blocks)').') AND miner = $2 ORDER BY height DESC', [$WINDOW_SIZE, $miner]);
    }
}

