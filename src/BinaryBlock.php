<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\Cryptonote;
use MoneroIntegrations\MoneroPhp\SHA3;
use mysql_xdevapi\Exception;

class BinaryBlock{

    private const HASH_SIZE = 32;
    private const DIFFICULTY_SIZE = 16;
    private const NONCE_SIZE = 4;

    private \stdClass $extra;

    /**
     * @return \stdClass
     */
    public function getExtra(): \stdClass {
        return $this->extra;
    }

    /**
     * @return int
     */
    public function getMajorVersion(): int {
        return $this->majorVersion;
    }

    /**
     * @return int
     */
    public function getMinorVersion(): int {
        return $this->minorVersion;
    }

    /**
     * @return int
     */
    public function getTimestamp(): int {
        return $this->timestamp;
    }

    /**
     * @return string
     */
    public function getMainParent(): string {
        return $this->mainParent;
    }

    /**
     * @return string
     */
    public function getNonce(): string {
        return $this->nonce;
    }

    /**
     * @return string
     */
    public function getExtraNonce(): string {
        return $this->extraNonce;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxVersion(): int {
        return $this->coinbaseTxVersion;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxUnlockTime(): int {
        return $this->coinbaseTxUnlockTime;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxInputCount(): int {
        return $this->coinbaseTxInputCount;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxInputType(): int {
        return $this->coinbaseTxInputType;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxGenHeight(): int {
        return $this->coinbaseTxGenHeight;
    }

    /**
     * @return object[]
     */
    public function getCoinbaseTxOutputs(): array {
        return $this->coinbaseTxOutputs;
    }

    /**
     * @return int
     */
    public function getCoinbaseTxReward(): int {
        $total = 0;
        foreach ($this->getCoinbaseTxOutputs() as $o){
            $total += $o->reward;
        }
        return $total;
    }

    /**
     * @return string
     */
    public function getCoinbaseTxExtra(): string {
        return $this->coinbaseTxExtra;
    }

    /**
     * @return string[]
     */
    public function getTransactions(): array {
        return $this->transactions;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getPublicSpendKey(): string {
        return $this->publicSpendKey;
    }

    /**
     * @return string
     */
    public function getPublicViewKey(): string {
        return $this->publicViewKey;
    }

    /**
     * @return string
     */
    public function getCoinbaseTxPrivateKey(): string {
        return $this->coinbaseTxPrivateKey;
    }

    /**
     * @return string
     */
    public function getCoinbaseTxId() : string {
        return $this->coinbaseTxId;
    }

    public function getPublicAddress() : string {
        $cn = new Cryptonote();
        return $cn->encode_address($this->getPublicSpendKey(), $this->getPublicViewKey());
    }

    /**
     * @return string
     */
    public function getParent(): string {
        return $this->parent;
    }

    /**
     * @return string[]
     */
    public function getUncles(): array {
        return $this->uncles;
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
    public function getDifficulty(): string {
        return $this->difficulty;
    }

    /**
     * @return string
     */
    public function getCumulativeDifficulty(): string {
        return $this->cumulativeDifficulty;
    }

    // MainChain data
    private int $majorVersion;
    private int $minorVersion;
    private int $timestamp;
    private string $mainParent;
    private string $nonce;
    private string $extraNonce;

    private int $coinbaseTxVersion;
    private int $coinbaseTxUnlockTime;
    private int $coinbaseTxInputCount;
    private int $coinbaseTxInputType;
    private int $coinbaseTxGenHeight;
    private array $coinbaseTxOutputs = [];
    private string $coinbaseTxExtra;
    private array $transactions = [];

    // SideChain data
    private string $id; // filled from coinbase tx
    private string $publicSpendKey;
    private string $publicViewKey;
    private string $coinbaseTxPrivateKey;
    private string $coinbaseTxId;
    private string $parent;
    private array $uncles = [];
    private int $height;
    private string $difficulty;
    private string $cumulativeDifficulty;



    public static function fromHexDump(string $hex): BinaryBlock {
        $index = 0;
        $bin = hex2bin($hex);

        if(strlen($bin) < 32){
            throw new \Exception("Invalid block data");
        }

        $version = self::readUINT64($bin, $index);
        $main = null;
        $side = null;

        $b = new BinaryBlock();
        $b->extra = new \stdClass();

        switch ($version){
            case 1:
                $b->extra->mainId = bin2hex(self::readBinary($bin, self::HASH_SIZE, $index));
                $b->extra->powHash = bin2hex(self::readBinary($bin, self::HASH_SIZE, $index));
                $b->extra->mainDifficulty = bin2hex(self::readBinary($bin, self::DIFFICULTY_SIZE, $index));

                $main = self::readBinary($bin, self::readUINT64($bin, $index), $index);
                $side = self::readBinary($bin, self::readUINT64($bin, $index), $index);

                try{
                    $b->extra->peer = self::readBinary($bin, self::readUINT64($bin, $index), $index);
                }catch (\Throwable $e){

                }
                break;
            case 0:
                $main = self::readBinary($bin, self::readUINT64($bin, $index), $index);
                $side = substr($bin, $index);
                break;

            default:
                throw new \Exception("Unknown block version $version");
        }

        //MainChain parsing
        $index = 0;

        $b->majorVersion = self::readUINT8($main, $index);
        $b->minorVersion = self::readUINT8($main, $index);
        $b->timestamp = self::readVARINT($main, $index);
        $b->mainParent = bin2hex(self::readBinary($main, self::HASH_SIZE, $index));
        $b->nonce = bin2hex(self::readBinary($main, self::NONCE_SIZE, $index));

        $txIndex = $index;
        $b->coinbaseTxVersion = self::readUINT8($main, $index);
        $b->coinbaseTxUnlockTime = self::readVARINT($main, $index);
        $b->coinbaseTxInputCount = self::readUINT8($main, $index);
        $b->coinbaseTxInputType = self::readUINT8($main, $index); //TODO: EXPECT TXIN_GEN
        $b->coinbaseTxGenHeight = self::readVARINT($main, $index);

        $outputCount = self::readVARINT($main, $index);
        for($i = 0; $i < $outputCount; ++$i){
            $reward = self::readVARINT($main, $index);
            $k = self::readUINT8($main, $index);
            switch ($k){
                case TXOUT_TO_KEY:
                    $ephPublicKey = bin2hex(self::readBinary($main, self::HASH_SIZE, $index));
                    $b->coinbaseTxOutputs[$i] = (object) [
                        "index" => $i,
                        "ephemeralPublicKey" => $ephPublicKey,
                        "reward" => $reward
                    ];
                case TXOUT_TO_TAGGED_KEY:
                    $viewTag = self::readUINT8($main, $index);
                    $b->coinbaseTxOutputs[$i]->viewTag = $viewTag;
                    break;
                default:
                    throw new Exception("Unknown $k TXOUT key");
            }
        }

        $txExtra = self::readBinary($main, self::readVARINT($main, $index), $index);
        $b->coinbaseTxExtra = bin2hex($txExtra);

        $txBytes = substr($main, $txIndex, $index - $txIndex);

        $txExtraBaseRCT = self::readUINT8($main, $index);

        //Tx Id calculation

        $hashes = [
            SHA3::init(SHA3::KECCAK_256)->absorb($txBytes)->squeeze(self::HASH_SIZE),

            // Base RCT, single 0 byte in miner tx
            SHA3::init(SHA3::KECCAK_256)->absorb(chr($txExtraBaseRCT))->squeeze(self::HASH_SIZE),
            // Prunable RCT, empty in miner tx
            str_repeat("\x00", self::HASH_SIZE)
        ];

        $b->coinbaseTxId = bin2hex(SHA3::init(SHA3::KECCAK_256)->absorb(implode($hashes))->squeeze(self::HASH_SIZE));

        $txCount = self::readVARINT($main, $index);
        for($i = 0; $i < $txCount; ++$i){
            $b->transactions[$i] = bin2hex(self::readBinary($main, self::HASH_SIZE, $index));
        }

        //TxExtra parsing

        $index = 0;
        self::readUINT8($txExtra, $index); //TODO: expect TX_EXTRA_TAG_PUBKEY
        $txPubKey = bin2hex(self::readBinary($txExtra, self::HASH_SIZE, $index));
        self::readUINT8($txExtra, $index); //TODO: expect TX_EXTRA_NONCE
        $extraNonceSize = self::readVARINT($txExtra, $index);
        $b->extraNonce = bin2hex(self::readBinary($txExtra, self::NONCE_SIZE, $index));
        for($i = self::NONCE_SIZE; $i < $extraNonceSize; ++$i){
            //TODO EXPECT
            $index++;
        }


        self::readUINT8($txExtra, $index); //TODO: expect TX_EXTRA_MERGE_MINING_TAG
        self::readUINT8($txExtra, $index); //TODO: expect HASH_SIZE
        $b->id = bin2hex(self::readBinary($txExtra, self::HASH_SIZE, $index));


        //Side Chain parsing

        $index = 0;

        $b->publicSpendKey = bin2hex(self::readBinary($side, self::HASH_SIZE, $index));
        $b->publicViewKey = bin2hex(self::readBinary($side, self::HASH_SIZE, $index));
        $b->coinbaseTxPrivateKey = bin2hex(self::readBinary($side, self::HASH_SIZE, $index));
        $b->parent = bin2hex(self::readBinary($side, self::HASH_SIZE, $index));

        $uncleCount = self::readVARINT($side, $index);
        for($i = 0; $i < $uncleCount; ++$i){
            $b->uncles[$i] = bin2hex(self::readBinary($side, self::HASH_SIZE, $index));
        }

        $b->height = self::readVARINT($side, $index);

        $lo = self::readVARINT($side, $index);
        $hi = self::readVARINT($side, $index);
        $b->difficulty = bin2hex(pack("J*", $hi, $lo));

        $lo = self::readVARINT($side, $index);
        $hi = self::readVARINT($side, $index);
        $b->cumulativeDifficulty = bin2hex(pack("J*", $hi, $lo));


        return $b;
    }

    private static function readUINT8(string $b, int &$index) : int {
        return ord(self::readBinary($b, 1, $index));
    }
    private static function readUINT64(string $b, int &$index) : int {
        return unpack("J", self::readBinary($b, 8, $index))[1] ?? 0;
    }

    private static function readBinary(string $b, int $len, int &$index) : string {
        if(strlen($b) < $index + $len){
            throw new \Exception("Reached end of data at $index for read of $len bytes");
        }
        return substr($b, ($index += $len) - $len, $len);
    }

    private static function readVARINT(string $d, int &$index) : int {
        $v = 0;
        $k = 0;

        do{
            $b = self::readUINT8($d, $index);
            $v |= ($b & 0x7f) << $k;
            $k += 7;
        }while(isset($d[$index]) and ($b & 0x80));

        return $v;
    }
}