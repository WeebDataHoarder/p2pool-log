<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\Cryptonote;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../src/constants.php";

$hex = trim($argv[1] === "-" ? stream_get_contents(STDIN) : file_get_contents($argv[1]));


$b = BinaryBlock::fromHexDump($hex);

$cn = new Cryptonote();

echo "id: ".$b->getId()."\n";
echo "mainId: ".($b->getExtra()->mainId ?? "none")."\n";
echo "PoW hash: ".($b->getExtra()->powHash ?? "none")."\n";
echo "main diff: ".($b->getExtra()->mainDifficulty ?? "none")."\n";

echo "\n\n=== MainChain data blob ===\n";
$mIndex = 0;
echo "majorVersion: ".$b->getMajorVersion()."\n";
echo "minorVersion: ".$b->getMinorVersion()."\n";
echo "timestamp: ".$b->getTimestamp()."\n";
echo "Parent: ".$b->getMainParent()."\n";
echo "Nonce: ".$b->getNonce()."\n";
echo "ExtraNonce: ".$b->getExtraNonce()."\n";

echo "TxVersion: ".$b->getCoinbaseTxVersion()."\n";
echo "TxUnlock: ".$b->getCoinbaseTxUnlockTime()."\n";
echo "TxInputCount: ".$b->getCoinbaseTxInputCount()."\n";
echo "TxInputType(Gen=0xFF): ".$b->getCoinbaseTxInputType()."\n";
echo "TxGenHeight: ".$b->getCoinbaseTxGenHeight()."\n";


echo "TxOutputCount: ".count($b->getCoinbaseTxOutputs())."\n";
foreach ($b->getCoinbaseTxOutputs() as $i => $o){
    $reward = $o->reward;
    $ephPublicKey = $o->ephemeralPublicKey;
    echo "TxOutput[$i]: $reward $ephPublicKey\n";
}
echo "TxExtra: ".$b->getCoinbaseTxExtra()."\n";

echo "TxCount: ".count($b->getTransactions())."\n";
foreach ($b->getTransactions() as $i => $tx){
    echo "TxHash[$i]: $tx\n";
}

echo "\n\n=== SideChain data blob ===\n";

$sIndex = 0;
$publicSpendKey = $b->getPublicSpendKey();
$publicViewKey = $b->getPublicViewKey();
echo "Miner pSpend: $publicSpendKey\n";
echo "Miner pView: $publicViewKey\n";
echo "Miner address: " . $cn->encode_address($publicSpendKey, $publicViewKey) . "\n\n";

$txPrivKey = $b->getCoinbaseTxPrivateKey();
echo "Coinbase privkey: $txPrivKey\n";
echo "SideChain Parent: ".$b->getParent()."\n";

foreach ($b->getUncles() as $i => $u){
    echo "Uncle[$i]: " .$u . "\n";
}

echo "SideChain height: ". $b->getHeight() . "\n";
echo "SideChain difficulty.: ". $b->getDifficulty() . "\n";
echo "SideChain cumDifficulty: ". $b->getCumulativeDifficulty() . "\n";