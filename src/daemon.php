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
    $data = json_decode(file_get_contents($path), false);

    return Block::fromJSONObject($database, $data, $uncles);
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

$runs = 0;

do{
    ++$runs;
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
        if($disk_block->isMainFound()){
            echo "[CHAIN] BLOCK FOUND! Main height " . $disk_block->getMainHeight() . ", main id " . $disk_block->getMainId() . "\n";
        }
        if($database->insertBlock($disk_block)){
            foreach ($uncles as $uncle){
                echo "[CHAIN] Inserting uncle " . $uncle->getId() . " @ " . $disk_block->getId() . " at " . $disk_block->getHeight() . "\n";
                $database->insertUncleBlock($uncle);
            }
            $knownTip = $disk_block->getHeight();
        }
    }

    if($runs % 10 === 0){ //Every 10 seconds or so
        foreach ($database->getAllFound(6) as $foundBlock){
            //Scan last 6 found blocks and set status accordingly if found/not found
            $tx = CoinbaseTransactionOutputs::fromTransactionId($foundBlock->getCoinbaseId());
            if($tx === null and (time() - $foundBlock->getTimestamp()) > 120){ // If more than two minutes have passed before we get utxo, remove from found
                echo "[CHAIN] Block that was found at main height " . $foundBlock->getMainHeight() . ", cannot find output, marking not found\n";
                $database->setBlockFound($foundBlock->getId(), false);
            }
        }
    }

    if($isFresh){
        //Do migration tasks

        foreach ($database->getBlocksByQuery("", []) as $block){
            if($block->isProofHigherThanDifficulty()){
                $tx = CoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
                if($tx !== null){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found\n";
                    $database->setBlockFound($block->getId(), true);
                }else if((time() - $block->getTimestamp()) <= 120){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found for now\n";
                    $database->setBlockFound($block->getId(), true);
                }
                sleep(1);
            }
        }

        foreach ($database->getUncleBlocksByQuery("", []) as $block){
            if($block->isProofHigherThanDifficulty()){
                $tx = CoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
                if($tx !== null){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found\n";
                    $database->setBlockFound($block->getId(), true);
                }else if((time() - $block->getTimestamp()) <= 120){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found for now\n";
                    $database->setBlockFound($block->getId(), true);
                }
                sleep(1);
            }
        }
        $isFresh = false;
    }

    sleep(1);
}while(true);
