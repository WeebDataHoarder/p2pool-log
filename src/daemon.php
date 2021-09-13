<?php

namespace p2pool;

use p2pool\db\Block;
use p2pool\db\Database;
use p2pool\db\UncleBlock;

require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/constants.php";

$database = new Database($argv[1]);

$tip = $database->getChainTip();
$isFresh = $tip === null;
$tip = $tip === null ? 1 : $tip->getHeight();

echo "[CHAIN] Last known database tip is $tip\n";

$blockExistsInApi = function (int $index) : bool{
    $s = (string) $index;
    $path = "/api/share/" . substr($index, -1) . "/$s";
    return file_exists($path);
};

//$top = Utils::findTopValue($blockExistsInApi, $tip, SIDECHAIN_PPLNS_WINDOW);

$startFrom = $isFresh ? Utils::findBottomValue($blockExistsInApi, 1, SIDECHAIN_PPLNS_WINDOW) : $tip;

/**
 * @param int $index
 * @param UncleBlock[] $uncles
 * @return Block
 * @throws \Exception
 */
function get_block_from_disk(int $index, array &$uncles = []) : Block {
    global $database;
    $s = (string) $index;
    $path = "/api/share/" . substr($index, -1) . "/$s";
    $data = json_decode(file_get_contents($path), true);

    $miner = $database->getOrCreateMinerByAddress($data["wallet"]);
    if($miner === null){
        throw new \Exception("Could not get or create miner");
    }


    $block = new Block($data["id"], (int) $data["height"], $data["prev_hash"], (int) $data["mheight"], $data["mhash"], (int) $data["diff"], $data["pow_hash"], (int) $data["ts"], $miner->getId(), $data["tx_coinbase"], $data["tx_priv"], (isset($data["block_found"]) and $data["block_found"] === "true"));

    if(isset($data["uncles"])){
        foreach ($data["uncles"] as $uncle){
            if(!isset($uncle["wallet"])){ //No known data
                continue;
            }
            $uncle_miner = $database->getOrCreateMinerByAddress($uncle["wallet"]);
            if($uncle_miner === null){
                throw new \Exception("Could not get or create miner");
            }

            $uncles[] = new UncleBlock($uncle["id"], $data["id"], (int) $data["height"], (int) $uncle["height"], $uncle["prev_hash"], (int) $uncle["ts"], $uncle_miner->getId());
        }
    }

    return $block;
}

if($isFresh){
    $uncles = [];
    $block = get_block_from_disk($startFrom, $uncles);
    $database->insertBlock($block);
    foreach ($uncles as $uncle){
        $database->insertUncleBlock($uncle);
    }
}

$knownTip = $startFrom;

echo "[CHAIN] Starting tip from height $knownTip\n";

do{
    $disk_tip = get_block_from_disk($knownTip);
    $db_tip = $database->getBlockByHeight($knownTip);

    if($db_tip->getId() !== $disk_tip->getId()){ //Reorg has happened, delete old values
        echo "[REORG] Reorg happened, deleting blocks to match from height ".$db_tip->getHeight()."\n";
        for($h = $knownTip; $h > 0; --$h){
            $db_block = $database->getBlockByHeight($h);
            $disk_block = get_block_from_disk($h);

            if($db_block->getPreviousId() === $disk_block->getPreviousId()){
                echo "[REORG] Found matching head " . $db_block->getPreviousId() . " at height ".($db_block->getHeight() - 1)."\n";
                $deleted = $database->deleteBlockById($db_block->getId());
                echo "[REORG] Deleted $deleted block(s).\n";
                echo "[REORG] Next tip ".$disk_block->getPreviousId()." : ".($disk_block->getHeight() - 1).".\n";
                $knownTip = $db_block->getHeight() - 1;
                break;
            }
        }
        continue;
    }

    $database->insertBlock($disk_tip); // Update found status?

    for($h = $knownTip + 1; $blockExistsInApi($h); ++$h){
        $uncles = [];
        $disk_block = get_block_from_disk($h, $uncles);
        $prev_block = $database->getBlockByHeight($h - 1);
        if($disk_block->getPreviousId() !== $prev_block->getId()){
            echo "[CHAIN] Possible reorg occurred, aborting insertion at height $h: prev id ".$disk_block->getPreviousId()." != id ".$prev_block->getId()."\n";
            break;
        }
        echo "[CHAIN] Inserting block " . $disk_block->getId() . " at height " . $disk_block->getHeight() . "\n";
        if($database->insertBlock($disk_block)){
            foreach ($uncles as $uncle){
                echo "[CHAIN] Inserting uncle " . $uncle->getId() . " @ " . $disk_block->getId() . " at " . $disk_block->getHeight() . "\n";
                $database->insertUncleBlock($uncle);
            }
            $knownTip = $disk_block->getHeight();
        }
    }

    sleep(1);
}while(true);
