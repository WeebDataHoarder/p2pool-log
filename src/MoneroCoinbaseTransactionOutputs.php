<?php

namespace p2pool;

use MoneroIntegrations\MoneroPhp\Cryptonote;
use p2pool\db\Miner;

class MoneroCoinbaseTransactionOutputs{
    private array $outputs = [];

    /** @var MoneroCoinbaseTransactionOutputs[] */
    private static array $cache = [];

    /**
     * @param Miner[] $miners
     */
    public function matchOutputs(array $miners, string $tx_privkey, array $hint = []): array {
        $matched = [];

        $addr = [];
        if(count($miners) === count($this->outputs)){
            foreach ($miners as $miner){
                $addr[] = gmp_init(implode(array_reverse(str_split($miner->getMoneroAddress()->getSpendPub(), 2))), 16);
            }

            usort($addr, function ($a, $b){
                return gmp_cmp($a, $b);
            });

            $addr = array_map(function ($i){
                return str_pad(gmp_strval($i, 16), 64, "0", STR_PAD_LEFT);
            }, $addr);
        }


        $outputs = $this->outputs;

        foreach ($outputs as $i => $o){
            foreach ($miners as $ix => $miner){
                $isValidHint = isset($hint[$miner->getId()]) and abs($hint[$miner->getId()] - (int) $o->amount) > 2;
                if(count($hint) !== 0 and !$isValidHint){
                    continue;
                }
                $ma = $miner->getMoneroAddress();

                $isValidIndex = array_search(implode(array_reverse(str_split($ma->getSpendPub(), 2))), $addr) === $o->index;

                if(
                    ($isValidHint and $isValidIndex) //Skip expensive check if sort AND amount match
                    or $ma->getEphemeralPublicKey($tx_privkey, $o->index) === $o->key
                ){
                    $matched[$miner->getId()] = clone $o;

                    unset($outputs[$i]);
                    unset($miners[$ix]);
                    unset($hint[$ix]);
                    break;
                }
            }

        }

        //Match missed items if equal amounts exist on each side
        if(count($outputs) === count($miners)){
            foreach ($miners as $ix => $miner){
                $ma = $miner->getMoneroAddress();
                foreach ($outputs as $i => $o){

                    if($ma->getEphemeralPublicKey($tx_privkey, $o->index) === $o->key){
                        $matched[$miner->getId()] = clone $o;

                        unset($outputs[$i]);
                        unset($miners[$ix]);
                        unset($hint[$ix]);
                        break;
                    }
                }
            }
        }

        return $matched;
    }

    public function getTotal() : int {
        $total = 0;
        foreach ($this->outputs as $o){
            $total += $o->amount;
        }
        return $total;
    }

    public static function fromTransactionId($txId): ?MoneroCoinbaseTransactionOutputs {
        if(isset(static::$cache[$txId])){
            return static::$cache[$txId];
        }else if (file_exists($path = "/cache/tx_{$txId}.json")){
            $outputs = json_decode(file_get_contents($path));
            $o = new MoneroCoinbaseTransactionOutputs();
            $o->outputs = $outputs;
            return static::$cache[$txId] = $o;
        }

        $ret = Utils::moneroRPC("get_transactions", [
            "txs_hashes" => [$txId],
            "decode_as_json" => true
        ]);

        if(isset($ret->txs[0]->as_json)){
            $outputs = [];
            $tx = json_decode($ret->txs[0]->as_json);
            foreach ($tx->vout as $i => $out){
                $outputs[$i] = (object) [
                    "amount" => (int) $out->amount,
                    "key" => $out->target->key,
                    "index" => (int) $i,
                    ];
            }

            $o = new MoneroCoinbaseTransactionOutputs();
            $o->outputs = $outputs;
            file_put_contents($path, json_encode($o->outputs));
            return static::$cache[$txId] = $o;
        }

        return null;
    }
}