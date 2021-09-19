<?php

namespace p2pool\db;

class CoinbaseTransaction{

    private string $id;

    private string $private_key;

    /** @var CoinbaseTransactionOutput[] */
    private array $outputs = [];

    /**
     * @param string $id
     * @param string $private_key
     * @param CoinbaseTransactionOutput[] $outputs
     */
    public function __construct(string $id, string $private_key, array $outputs) {
        $this->id = $id;
        $this->private_key = $private_key;
        $this->outputs = $outputs;
    }

    public static function getEphemeralPublicKey(CoinbaseTransaction $tx, Miner $miner, int $index = null): string {
        $o = $index === null ? $tx->getOutputByMinerId($miner->getId()) : $tx->getOutputByIndex($index);
        if($o === null or $o->getMiner() !== $miner->getId()){
            throw new \Exception("Could not find output with provided details");
        }

        return $miner->getMoneroAddress()->getEphemeralPublicKey($tx->getPrivateKey(), $o->getIndex());
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
    public function getPrivateKey() : string{
        return $this->private_key;
    }

    /**
     * @return CoinbaseTransactionOutput[]
     */
    public function getOutputs(): array {
        return $this->outputs;
    }

    /**
     * @param int $index
     * @return CoinbaseTransactionOutput|null
     */
    public function getOutputByIndex(int $index) : ?CoinbaseTransactionOutput {
        return $this->outputs[$index] ?? null;
    }

    /**
     * @param int $miner
     * @return CoinbaseTransactionOutput|null
     */
    public function getOutputByMinerId(int $miner) : ?CoinbaseTransactionOutput {
        foreach ($this->getOutputs() as $output){
            if($output->getMiner() === $miner){
                return $output;
            }
        }
        return null;
    }


}