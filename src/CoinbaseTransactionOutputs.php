<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\Cryptonote;
use p2pool\db\Miner;

class CoinbaseTransactionOutputs{
    private array $outputs = [];

    /** @var CoinbaseTransactionOutputs[] */
    private static array $cache = [];

    /**
     * @param Miner[] $miners
     */
    public function matchOutputs(array $miners, string $tx_privkey): array {
        $matched = [];
        foreach ($miners as $miner){
            $ma = $miner->getMoneroAddress();
            foreach ($this->outputs as $i => $o){
                if(isset($matched[$i])){
                    continue;
                }
                if($ma->getEphemeralPublicKey($tx_privkey, $o->index) === $o->key){
                    $matched[$miner->getId()] = clone $o;
                }
            }
        }

        return $matched;
    }

    public static function fromTransactionId($txId): ?CoinbaseTransactionOutputs {
        if(isset(static::$cache[$txId])){
            return static::$cache[$txId];
        }

        $ch = curl_init(getenv("MONEROD_RPC_URL") . "get_transactions");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "txs_hashes" => [$txId],
            "decode_as_json" => true
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $ret = @json_decode(curl_exec($ch));
        if(isset($ret->txs[0]->as_json)){
            $outputs = [];
            $tx = json_decode($ret->txs[0]->as_json);
            foreach ($tx->vout as $i => $out){
                $outputs[$i] = (object) [
                    "amount" => $out->amount,
                    "key" => $out->target->key,
                    "index" => $i,
                    ];
            }

            $o = new CoinbaseTransactionOutputs();
            $o->outputs = $outputs;
            return static::$cache[$txId] = $o;
        }

        return null;
    }
}