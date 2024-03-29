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

if(isset($argv[2]) and isset($argv[3])){
    for($h = $argv[2]; $h < $argv[3]; ++$h){
        echo " inserting $h...\n";
        $uncles = [];
        $block = $api->getShareEntry($h, $uncles);
        if($block === null){
            continue;
        }
        $uncles = [];
        $id = $block->getId();
        $block = $api->getShareFromRawEntry($block->getId(), $uncles, true);
        if($block === null){
            echo "[CHAIN] Could not find block $id to insert at height $h. Check disk or uncles\n";
        }
        $database->insertBlock($block);
        foreach ($uncles as $uncle){
            $database->insertUncleBlock($uncle);
        }
    }
}

$tip = $database->getChainTip();
$isFresh = $tip === null;
$tip = $tip === null ? 1 : $tip->getHeight();

echo "[CHAIN] Last known database tip is $tip\n";

$diskTip = $api->getPoolStats()->pool_statistics->height;

echo "[CHAIN] Last known disk tip is $diskTip\n";

//$top = Utils::findTopValue([$api, "blockExists"], $tip, SIDECHAIN_PPLNS_WINDOW);

$startFrom = $tip;

if($diskTip > $tip and !$api->blockExists($tip + 1)){
    for($i = $diskTip; $api->blockExists($i); --$i){
        $startFrom = $i;
    }
}

if($isFresh or $startFrom != $tip){
    $uncles = [];
    $block = $api->getShareEntry($startFrom, $uncles);
    $uncles = [];
    $id = $block->getId();
    $block = $api->getShareFromRawEntry($block->getId(), $uncles, true);
    if($block === null){
        echo "[CHAIN] Could not find block $id to insert at height $startFrom. Check disk or uncles\n";
        exit(1);
    }
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

    $payout_hint = $api->getBlockWindowPayouts($b);
    /** @var Miner[] $miners */
    $miners = [];
    foreach ($payout_hint as $minerId => $amount){
        $miners[$minerId] = $api->getDatabase()->getMiner($minerId);
    }

    $outputs = $tx->matchOutputs($miners, $b->getCoinbasePrivkey());
    if(count($outputs) === count($miners) and count($outputs) === count($tx->getRawOutputs())){
        $new_outputs = [];
        foreach ($outputs as $minerId => $o){
            $new_outputs[(int) $o->index] = new CoinbaseTransactionOutput($b->getCoinbaseId(), $o->index, $o->amount, $minerId);
        }

        $coinbaseOutput = new CoinbaseTransaction($b->getCoinbaseId(), $b->getCoinbasePrivkey(), $new_outputs);
        return $api->getDatabase()->insertCoinbaseTransaction($coinbaseOutput);
    }else{

        echo "[OUTPUT] Could not find all outputs! Coinbase transaction " . $b->getCoinbaseId() . ", got ".count($outputs).", expected ".count($miners).", real ".count($tx->getRawOutputs())."\n";
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

$difficultyCache = [];

function cacheHeightDiff(int $height){
    global $difficultyCache;
    if(!isset($difficultyCache[$height])){
        $header = Utils::monero_GetBlockHeaderByHeight($height);
        usleep(100000);
        if($header === null){
            $template = Utils::monero_GetBlockTemplate();
            if($template !== null){
                $difficultyCache[$template->height] = str_pad(gmp_strval(gmp_init($template->difficulty), 16), 32, "0", STR_PAD_LEFT);
            }
        }else{
            $difficultyCache[$header->height] = str_pad(gmp_strval(gmp_init($header->difficulty), 16), 32, "0", STR_PAD_LEFT);
        }

        if(count($difficultyCache) > 512){
            array_shift($difficultyCache);
        }
    }
}

foreach ($database->getBlocksByQuery("WHERE miner_main_difficulty = 'ffffffffffffffffffffffffffffffff' ORDER BY main_height ASC") as $b){ //Fix blocks without height
    cacheHeightDiff($b->getMainHeight());
    if(isset($difficultyCache[$b->getMainHeight()])){
        echo "[CHAIN] Filling main difficulty for share " . $b->getHeight() . ", main height " . $b->getMainHeight() . "\n";
        $diff = $difficultyCache[$b->getMainHeight()];
        $database->setBlockMainDifficulty($b->getId(), $diff);
        $b = $database->getBlockById($b->getId());

        if(!$b->isMainFound() and $b->isProofHigherThanDifficulty()){
            echo "[CHAIN] BLOCK FOUND! Main height " . $b->getMainHeight() . ", main id " . $b->getMainId() . "\n";
            $tx = null;
            try{
                $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($b->getCoinbaseId());
            }catch (\Throwable $e){

            }
            if($tx !== null){
                $database->setBlockFound($b->getId(), true);
                processFoundBlockWithTransaction($b, $tx);
            }
        }
    }
}
foreach ($database->getUncleBlocksByQuery("WHERE miner_main_difficulty = 'ffffffffffffffffffffffffffffffff' ORDER BY main_height ASC") as $b){ //Fix uncle without height
    cacheHeightDiff($b->getMainHeight());
    if(isset($difficultyCache[$b->getMainHeight()])){
        echo "[CHAIN] Filling main difficulty for uncle share " . $b->getHeight() . ", main height " . $b->getMainHeight() . "\n";
        $diff = $difficultyCache[$b->getMainHeight()];
        $database->setBlockMainDifficulty($b->getId(), $diff);
        $b = $database->getUncleById($b->getId());

        if(!$b->isMainFound() and $b->isProofHigherThanDifficulty()){
            echo "[CHAIN] BLOCK FOUND! Main height " . $b->getMainHeight() . ", main id " . $b->getMainId() . "\n";
            $tx = null;
            try{
                $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($b->getCoinbaseId());
            }catch (\Throwable $e){

            }
            if($tx !== null){
                $database->setBlockFound($b->getId(), true);
                processFoundBlockWithTransaction($b, $tx);
            }
        }
    }
}

do{
    ++$runs;
    $disk_tip = $api->getShareEntry($knownTip);
    $disk_tip = $api->getShareFromRawEntry($disk_tip->getId()) ?? $disk_tip;
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

    for($h = $knownTip + 1; $api->blockExists($h); ++$h){
        /** @var UncleBlock[] $uncles */
        $uncles = [];
        $disk_block = $api->getShareEntry($h, $uncles);
        if($disk_block === null){
            break;
        }
        $id = $disk_block->getId();

        $uncles = [];
        $disk_block = $api->getShareFromRawEntry($disk_block->getId(), $uncles, true);
        if($disk_block === null){
            echo "[CHAIN] Could not find block $id to insert at height $h. Check disk or uncles\n";
            break;
        }

        $prev_block = $database->getBlockByHeight($h - 1);
        if($disk_block->getPreviousId() !== $prev_block->getId()){
            echo "[CHAIN] Possible reorg occurred, aborting insertion at height $h: prev id ".$disk_block->getPreviousId()." != id ".$prev_block->getId()."\n";
            break;
        }
        echo "[CHAIN] Inserting block " . $disk_block->getId() . " at height " . $disk_block->getHeight() . "\n";

        cacheHeightDiff($disk_block->getMainHeight());

        if($database->insertBlock($disk_block, $difficultyCache[$disk_block->getMainHeight()] ?? null)){
            foreach ($uncles as $uncle){
                echo "[CHAIN] Inserting uncle " . $uncle->getId() . " @ " . $disk_block->getId() . " at " . $disk_block->getHeight() . "\n";
                $database->insertUncleBlock($uncle, $difficultyCache[$uncle->getMainHeight()] ?? null);

                if($uncle->isMainFound()){
                    echo "[CHAIN] BLOCK FOUND! (uncle) Main height " . $uncle->getMainHeight() . ", main id " . $uncle->getMainId() . "\n";
                    $tx = null;
                    try{
                        $tx = MoneroCoinbaseTransactionOutputs::fromBinaryBlock(BinaryBlock::fromHexDump($api->getRawBlock($uncle->getId())));
                    }catch (\Throwable $e){

                    }
                    if($tx !== null){
                        processFoundBlockWithTransaction($uncle, $tx);
                    }
                }
            }
            $knownTip = $disk_block->getHeight();
        }
        if($disk_block->isMainFound()){
            echo "[CHAIN] BLOCK FOUND! Main height " . $disk_block->getMainHeight() . ", main id " . $disk_block->getMainId() . "\n";
            $tx = null;
            try{
                $tx = MoneroCoinbaseTransactionOutputs::fromBinaryBlock(BinaryBlock::fromHexDump($api->getRawBlock($disk_block->getId())));
            }catch (\Throwable $e){

            }
            if($tx !== null){
                processFoundBlockWithTransaction($disk_block, $tx);
            }
        }
    }

    if($runs % 10 === 0){ //Every 10 seconds or so
        foreach ($database->getAllFound(10) as $foundBlock){
            //Scan last 10 found blocks and set status accordingly if found/not found

            // Look between +1 block and +4 blocks
            if(($disk_tip->getMainHeight() - 1) > $foundBlock->getMainHeight() and ($disk_tip->getMainHeight() - 5) < $foundBlock->getMainHeight() or $database->getCoinbaseTransaction($foundBlock) === null){
                $tx = MoneroCoinbaseTransactionOutputs::fromTransactionId($foundBlock->getCoinbaseId(), false);
                if($tx === null){ // If more than two minutes have passed before we get utxo, remove from found
                    echo "[CHAIN] Block that was found at main height " . $foundBlock->getMainHeight() . ", cannot find output, marking not found\n";
                    $database->setBlockFound($foundBlock->getId(), false);
                }else{
                    processFoundBlockWithTransaction($foundBlock, $tx);
                }
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
