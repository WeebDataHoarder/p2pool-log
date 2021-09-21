<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\Cryptonote;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../src/constants.php";

$block = hex2bin(trim($argv[1] === "-" ? stream_get_contents(STDIN) : file_get_contents($argv[1])));


function readUINT8(string $b, int &$index) : int {
    return ord(readBinary($b, 1, $index));
}
function readUINT64(string $b, int &$index) : int {
    return unpack("J", readBinary($b, 8, $index))[1] ?? 0;
}

function readBinary(string $b, int $len, int &$index) : string {
    return substr($b, ($index += $len) - $len, $len);
}

function readVARINT(string $d, int &$index) : int {
    $v = 0;
    $k = 0;

    do{
        $b = ord($d[$index++]);
        $v |= ($b & 0x7f) << $k;
        $k += 7;
    }while(isset($d[$index]) and ($b & 0x80));

    return $v;
}

$index = 0;

$version = readUINT64($block, $index);
$mainDataLength = readUINT64($block, $index);

$mainData = substr($block, $index, $mainDataLength);
$index += $mainDataLength;
$sideData = substr($block, $index);


$cn = new Cryptonote();

echo "\n\n=== MainChain data blob ===\n";
$mIndex = 0;
echo "majorVersion: ".readUINT8($mainData, $mIndex)."\n";
echo "minorVersion: ".readUINT8($mainData, $mIndex)."\n";
echo "timestamp: ".readVARINT($mainData, $mIndex)."\n";
echo "Parent: ".bin2hex(readBinary($mainData, 32, $mIndex))."\n";
echo "Nonce: ".bin2hex(readBinary($mainData, 4, $mIndex))."\n";

echo "TxVersion: ".readUINT8($mainData, $mIndex)."\n";
echo "TxUnlock: ".readVARINT($mainData, $mIndex)."\n";
echo "Tx???: ".readUINT8($mainData, $mIndex)."\n";
echo "TxType(Gen=0xFF): ".readUINT8($mainData, $mIndex)."\n";
echo "TxGenHeight: ".readVARINT($mainData, $mIndex)."\n";

$outputCount = readVARINT($mainData, $mIndex);
echo "TxOutputCount: $outputCount\n";
for($i = 0; $i < $outputCount; ++$i){
    $reward = readVARINT($mainData, $mIndex);
    $k = readUINT8($mainData, $mIndex);
    $ephPublicKey = bin2hex(readBinary($mainData, 32, $mIndex));
    echo "TxOutput[$i]: $reward $k $ephPublicKey\n";
}
echo "TxExtra: ".bin2hex(readBinary($mainData, readVARINT($mainData, $mIndex), $mIndex))."\n";
echo "TxExtraNULL: ".readUINT8($mainData, $mIndex)."\n";
$txCount = readVARINT($mainData, $mIndex);
echo "TxCount: $txCount\n";
for($i = 0; $i < $txCount; ++$i){
    echo "TxHash[$i]: ".bin2hex(readBinary($mainData, 32, $mIndex))."\n";
}

echo "\n\n=== SideChain data blob ===\n";

$sIndex = 0;
$publicSpendKey = bin2hex(readBinary($sideData, 32, $sIndex));
$publicViewKey = bin2hex(readBinary($sideData, 32, $sIndex));
echo "Miner pSpend: $publicSpendKey\n";
echo "Miner pView: $publicViewKey\n";
echo "Miner address: " . $cn->encode_address($publicSpendKey, $publicViewKey) . "\n\n";

$txPrivKey = bin2hex(readBinary($sideData, 32, $sIndex));
echo "Coinbase privkey: $txPrivKey\n";
echo "SideChain Parent: ".bin2hex(readBinary($sideData, 32, $sIndex))."\n";

$uncleCount = readVARINT($sideData, $sIndex);
for($i = 0; $i < $uncleCount; ++$i){
    echo "Uncle[$i]: " .bin2hex(readBinary($sideData, 32, $sIndex)) . "\n";
}

echo "SideChain height: ". readVARINT($sideData, $sIndex) . "\n";
echo "SideChain difficulty.lo: ". readVARINT($sideData, $sIndex) . "\n";
echo "SideChain difficulty.hi: ". readVARINT($sideData, $sIndex) . "\n";
echo "SideChain cumDifficulty.lo: ". readVARINT($sideData, $sIndex) . "\n";
echo "SideChain cumDifficulty.hi: ". readVARINT($sideData, $sIndex) . "\n";