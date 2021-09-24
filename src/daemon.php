<?php

namespace p2pool;

use p2pool\db\Block;
use p2pool\db\CoinbaseTransaction;
use p2pool\db\CoinbaseTransactionOutput;
use p2pool\db\Database;
use p2pool\db\Miner;
use p2pool\db\UncleBlock;

require_once __DIR__ . "/../vendor/autoload.php";

require_once __DIR__ . "/constants.php";

$api = new P2PoolAPI(new Database($argv[1]), "/api");
$database = $api->getDatabase();

$tip = $database->getChainTip();
$isFresh = $tip === null;
$tip = $tip === null ? 1 : $tip->getHeight();

echo "[CHAIN] Last known database tip is $tip\n";

//$top = Utils::findTopValue([$api, "blockExists"], $tip, SIDECHAIN_PPLNS_WINDOW);

$startFrom = $isFresh ? Utils::findBottomValue([$api, "blockExists"], 1, SIDECHAIN_PPLNS_WINDOW) : $tip;

if($isFresh){
    $uncles = [];
    $block = $api->getShareEntry($startFrom, $uncles);
    $database->insertBlock($block);
    foreach ($uncles as $uncle){
        $database->insertUncleBlock($uncle);
    }
}
//TODO: handle jumps in blocks (missing data)

$knownTip = $startFrom;

echo "[CHAIN] Starting tip from height $knownTip\n";

$runs = 0;

function processFoundBlockWithTransaction(Block $b, MoneroCoinbaseTransactionOutputs $tx){
    global $api;
    if($api->getDatabase()->coinbaseTransactionExists($b)){
        return true;
    }
    echo "[OUTPUT] Trying to insert transaction " . $b->getCoinbaseId() . "\n";

    $payout_hint = $api->getWindowPayouts($b->getHeight(), $b->getCoinbaseReward());
    /** @var Miner[] $miners */
    $miners = [];
    foreach ($payout_hint as $minerId => $amount){
        $miners[$minerId] = $api->getDatabase()->getMiner($minerId);
    }

    $outputs = $tx->matchOutputs($miners, $b->getCoinbasePrivkey());
    if(count($outputs) === count($miners)){
        $new_outputs = [];
        foreach ($outputs as $minerId => $o){
            $new_outputs[(int) $o->index] = new CoinbaseTransactionOutput($b->getCoinbaseId(), $o->index, $o->amount, $minerId);
        }

        $coinbaseOutput = new CoinbaseTransaction($b->getCoinbaseId(), $b->getCoinbasePrivkey(), $new_outputs);
        return $api->getDatabase()->insertCoinbaseTransaction($coinbaseOutput);
    }else{

        echo "[OUTPUT] Could not find all outputs! Coinbase transaction " . $b->getCoinbaseId() . "\n";
    }

    return false;
}

if(iterator_to_array($database->query("SELECT COUNT(*) as count FROM coinbase_outputs;", []))[0]["count"] == 0){ //No transactions inserted yet!
    foreach ($database->getAllFound() as $block){
        echo "[OUTPUT] Trying to insert old coinbase transaction " . $block->getCoinbaseId() . "\n";
        $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
        if($tx !== null){
            processFoundBlockWithTransaction($block, $tx);
        }
    }
}

do{
    ++$runs;
    $disk_tip = $api->getShareEntry($knownTip);
    $db_tip = $database->getBlockByHeight($knownTip);

    if($db_tip->getId() !== $disk_tip->getId()){ //Reorg has happened, delete old values
        echo "[REORG] Reorg happened, deleting blocks to match from height ".$db_tip->getHeight()."\n";
        for($h = $knownTip; $h > 0; --$h){
            $db_block = $database->getBlockByHeight($h);
            $disk_block = $api->getShareEntry($h);

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

    for($h = $knownTip + 1; $api->blockExists($h); ++$h){
        $uncles = [];
        $disk_block = $api->getShareEntry($h, $uncles);
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

                if($uncle->isMainFound()){
                    echo "[CHAIN] BLOCK FOUND! (uncle) Main height " . $uncle->getMainHeight() . ", main id " . $uncle->getMainId() . "\n";
                    $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($uncle->getCoinbaseId());
                    if($tx !== null){
                        processFoundBlockWithTransaction($uncle, $tx);
                    }
                }
            }
            $knownTip = $disk_block->getHeight();
        }
        if($disk_block->isMainFound()){
            echo "[CHAIN] BLOCK FOUND! Main height " . $disk_block->getMainHeight() . ", main id " . $disk_block->getMainId() . "\n";
            $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($disk_block->getCoinbaseId());
            if($tx !== null){
                processFoundBlockWithTransaction($disk_block, $tx);
            }
        }
    }

    if($runs % 10 === 0){ //Every 10 seconds or so
        foreach ($database->getAllFound(10) as $foundBlock){
            //Scan last 6 found blocks and set status accordingly if found/not found
            $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($foundBlock->getCoinbaseId());
            if($tx === null and (time() - $foundBlock->getTimestamp()) > 120){ // If more than two minutes have passed before we get utxo, remove from found
                echo "[CHAIN] Block that was found at main height " . $foundBlock->getMainHeight() . ", cannot find output, marking not found\n";
                $database->setBlockFound($foundBlock->getId(), false);
            }elseif ($tx !== null){
                processFoundBlockWithTransaction($foundBlock, $tx);
            }
        }
    }

    if($isFresh){
        //Do migration tasks

        foreach ($database->getBlocksByQuery("", []) as $block){
            if($block->isProofHigherThanDifficulty()){
                $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
                if($tx !== null){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found\n";
                    $database->setBlockFound($block->getId(), true);
                    processFoundBlockWithTransaction($block, $tx);
                }else if((time() - $block->getTimestamp()) <= 120){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found for now\n";
                    $database->setBlockFound($block->getId(), true);
                }
                sleep(1);
            }
        }

        foreach ($database->getUncleBlocksByQuery("", []) as $block){
            if($block->isProofHigherThanDifficulty()){
                $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
                if($tx !== null){
                    echo "[CHAIN] Marking block ".$block->getMainId()." as found\n";
                    $database->setBlockFound($block->getId(), true);
                    processFoundBlockWithTransaction($block, $tx);
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
