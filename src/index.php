<?php

namespace p2pool;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/constants.php";

use p2pool\db\Block;
use p2pool\db\Database;
use p2pool\db\Miner;
use p2pool\db\UncleBlock;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

$api = new P2PoolAPI(new Database($argv[1]), "/api");

function getBlockAsJSONData(P2PoolAPI $api, Block $b, $extraUncleData = false, $extraCoinbaseOutputData = false) : array{
    $data = [
        "id" => $b->getId(),
        "height" => $b->getHeight(),
        "previous_id" => $b->getPreviousId(),
        "coinbase" => [
            "id" => $b->getCoinbaseId(),
            "reward" => $b->getCoinbaseReward(),
            "private_key" => $b->getCoinbasePrivkey(),
        ],
        "difficulty" => $b->getDifficulty(),
        "timestamp" => $b->getTimestamp(),
        "miner" => $api->getDatabase()->getMiner($b->getMiner())->getAddress(),
        "pow" => $b->getPowHash(),
        "main" => [
            "id" => $b->getMainId(),
            "height" => $b->getMainHeight(),
            "found" => $b->isMainFound()
        ],
        "template" => [
            "id" => $b->getMinerMainId(),
            "difficulty" => $b->getMinerMainDifficulty()
        ]
    ];

    if($extraCoinbaseOutputData){
        $tx = $b->isMainFound() ? CoinbaseTransactionOutputs::fromTransactionId($b->getCoinbaseId()) : null;
        $data["coinbase"]["payouts"] = [];
        $payouts = $api->getWindowPayouts($b->getHeight(), $b->getCoinbaseReward() === 0 ? ($tx !== null ? $tx->getTotal() : 0) : $b->getCoinbaseReward());
        if($b->isMainFound() and $tx !== null and count($payouts) !== 0){
            /** @var Miner[] $miners */
            $miners = [];
            foreach ($payouts as $minerId => $amount){
                $miners[$minerId] = $api->getDatabase()->getMiner($minerId);
            }

            foreach($tx->matchOutputs($miners, $b->getCoinbasePrivkey(), $payouts) as $minerId => $o){
                $data["coinbase"]["payouts"][$miners[$minerId]->getAddress()] = [
                    "amount" => (int) $o->amount,
                    "index" => (int) $o->index,
                    "public_key" => $o->key
                ];
            }

            if(count($data["coinbase"]["payouts"]) !== count($miners)){
                echo "Unmatched amount of payouts on block " . $b->getId() . " :: " . $b->getMainId() . " => expected ". count($miners) ." got " . count($data["coinbase"]["payouts"]) . "\n";
            }
        }else{
            foreach ($payouts as $minerId => $amount){
                $data["coinbase"]["payouts"][$api->getDatabase()->getMiner($minerId)] = [
                    "amount" => $amount
                ];
            }
        }
    }

    if($b instanceof UncleBlock){
        $data["parent"] = [
            "id" => $b->getParentId(),
            "height" => $b->getParentHeight()
        ];
    }else{
        $data["uncles"] = [];
        foreach ($api->getDatabase()->getUnclesByParentId($b->getId()) as $u){
            if(!$extraUncleData){
                $data["uncles"][] = [
                    "id" => $u->getId(),
                    "height" => $u->getHeight(),
                ];
            }else{
                $data["uncles"][] = [
                    "id" => $u->getId(),
                    "height" => $u->getHeight(),
                    "difficulty" => $u->getDifficulty(),
                    "timestamp" => $u->getTimestamp(),
                    "miner" => $api->getDatabase()->getMiner($u->getMiner())->getAddress(),
                    "pow" => $u->getPowHash(),
                ];
            }
        }
    }

    return $data;
}

$server = new HttpServer(function (ServerRequestInterface $request){
    global $api;
    if($request->getMethod() !== "GET"){ //TODO: remote calls
        return new Response(403);
    }

    if(preg_match("#^/api/miner_info/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }
        $returnData = [
            "id" => $miner->getId(),
            "address" => $miner->getAddress(),
            "shares" => []
        ];

        $blockData = iterator_to_array($api->getDatabase()->query("SELECT COUNT(*) as count, MAX(height) as last_height FROM blocks WHERE blocks.miner = $1;", [$miner->getId()]))[0];
        $uncleData = iterator_to_array($api->getDatabase()->query("SELECT COUNT(*) as count, MAX(parent_height) as last_height FROM uncles WHERE uncles.miner = $1;", [$miner->getId()]))[0];

        $returnData["shares"]["blocks"] = (int) $blockData["count"];
        $returnData["shares"]["uncles"] = (int) $uncleData["count"];

        $returnData["last_share_height"] = (int) max($blockData["count"] > 0 ? $blockData["last_height"] : 0, $uncleData["count"] > 0 ? $uncleData["last_height"] : 0);
        $returnData["last_share_timestamp"] = $api->getDatabase()->getBlockByHeight($returnData["last_share_height"])->getTimestamp();


        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    if(preg_match("#^/api/shares_in_range_window/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }

        parse_str($request->getUri()->getQuery(), $params);

        $window = isset($params["window"]) ? (int) min(SIDECHAIN_PPLNS_WINDOW * 4, $params["window"]) : SIDECHAIN_PPLNS_WINDOW;
        $from = isset($params["from"]) ? (int) min(0, $params["from"]) : null;

        $returnData = [

        ];

        foreach ($api->getDatabase()->getBlocksByMinerIdInWindow($miner->getId(), $from, $window) as $block){
            $r = [
                "id" => $block->getId(),
                "height" => $block->getHeight(),
                "weight" => gmp_intval($weight = gmp_init($block->getDifficulty(), 16)),
                "uncles" => []
            ];

            foreach ($api->getDatabase()->getUnclesByParentId($block->getId()) as $u){
                $uncle_weight = gmp_div(gmp_mul(gmp_init($u->getDifficulty(), 16), SIDECHAIN_UNCLE_PENALTY), 100);
                $weight = gmp_add($weight, $uncle_weight);
                $r["uncles"][] = [
                    "id" => $u->getId(),
                    "height" => $u->getHeight(),
                    "weight" => gmp_intval($uncle_weight)
                ];
            }

            if(count($r["uncles"]) === 0){
                unset($r["uncles"]);
            }

            $returnData[] = $r;

        }

        foreach ($api->getDatabase()->getUnclesByMinerIdInWindow($miner->getId(), $from, $window) as $uncle){
            $returnData[] = [
                "parent" => [
                    "id" => $uncle->getParentId(),
                    "height" => $uncle->getParentHeight()
                ],
                "id" => $uncle->getId(),
                "height" => $uncle->getHeight(),
                "weight" => gmp_intval(gmp_div(gmp_mul(gmp_init($uncle->getDifficulty(), 16), 100 - SIDECHAIN_UNCLE_PENALTY), 100))
            ];
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    if(preg_match("#^/api/shares_in_current_window/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }

        $returnData = [

        ];

        foreach ($api->getDatabase()->getBlocksByMinerIdInWindow($miner->getId()) as $block){
            $r = [
                "id" => $block->getId(),
                "height" => $block->getHeight(),
                "weight" => gmp_intval($weight = gmp_init($block->getDifficulty(), 16)),
                "uncles" => []
            ];

            foreach ($api->getDatabase()->getUnclesByParentId($block->getId()) as $u){
                $uncle_weight = gmp_div(gmp_mul(gmp_init($u->getDifficulty(), 16), SIDECHAIN_UNCLE_PENALTY), 100);
                $weight = gmp_add($weight, $uncle_weight);
                $r["uncles"][] = [
                    "id" => $u->getId(),
                    "height" => $u->getHeight(),
                    "weight" => gmp_intval($uncle_weight)
                ];
            }

            if(count($r["uncles"]) === 0){
                unset($r["uncles"]);
            }

            $returnData[] = $r;

        }

        foreach ($api->getDatabase()->getUnclesByMinerIdInWindow($miner->getId()) as $uncle){
            $returnData[] = [
                "parent" => [
                    "id" => $uncle->getParentId(),
                    "height" => $uncle->getParentHeight()
                ],
                "id" => $uncle->getId(),
                "height" => $uncle->getHeight(),
                "weight" => gmp_intval(gmp_div(gmp_mul(gmp_init($uncle->getDifficulty(), 16), 100 - SIDECHAIN_UNCLE_PENALTY), 100))
            ];
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    if(preg_match("#^/api/payouts/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }

        $limit = isset($params["search_limit"]) ? (int) min(50, $params["search_limit"]) : 10;

        $returnData = [];
        foreach ($api->getDatabase()->getAllFound($limit) as $block){
            $window_payouts = $api->getWindowPayouts($block->getHeight(), $block->getCoinbaseReward());

            if(!isset($window_payouts[$miner->getId()])){
                continue;
            }

            $o = CoinbaseTransactionOutputs::fromTransactionId($block->getCoinbaseId());
            if($o !== null){
                $outputs = $o->matchOutputs([$miner], $block->getCoinbasePrivkey(), $window_payouts);

                if(isset($outputs[$miner->getId()])){
                    $output = $outputs[$miner->getId()];
                    $returnData[] = [
                        "id" => $block->getId(),
                        "height" => $block->getHeight(),
                        "main" => [
                            "id" => $block->getMainId(),
                            "height" => $block->getMainHeight(),
                        ],
                        "timestamp" => $block->getTimestamp(),
                        "coinbase" => [
                            "id" => $block->getCoinbaseId(),
                            "reward" => $output->amount,
                            "private_key" => $block->getCoinbasePrivkey(),
                            "index" => $output->index
                        ],
                    ];
                }
            }
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    if(preg_match("#^/api/prove/(?P<height>[0-9]+)/(?P<miner>[0-9]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $b = $api->getDatabase()->getBlockByHeight($matches["height"]);
        $miner = $api->getDatabase()->getMiner($matches["miner"]);
        if($b === null or $miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }
        return new Response(302, [
            "Location" => "https://www.exploremonero.com/receipt/".$b->getCoinbaseId()."/".$miner->getAddress()."/".$b->getCoinbasePrivkey()
        ]);
    }

    if(preg_match("#^/api/last_found(?P<kind>|/raw|/info)$#", $request->getUri()->getPath(), $matches) > 0){
        return new Response(302, [
            "Location" => "/api/block_by_id/" . $api->getDatabase()->getLastFound()->getId() . $matches["kind"] . ($request->getUri()->getQuery() !== "" ? "?" . $request->getUri()->getQuery() : "")
        ]);
    }

    if(preg_match("#^/api/tip(?P<kind>|/raw|/info)$#", $request->getUri()->getPath(), $matches) > 0){
        return new Response(302, [
            "Location" => "/api/block_by_id/" . $api->getDatabase()->getChainTip()->getId() . $matches["kind"] . ($request->getUri()->getQuery() !== "" ? "?" . $request->getUri()->getQuery() : "")
        ]);
    }

    if(preg_match("#^/api/found_blocks$#", $request->getUri()->getPath(), $matches) > 0){
        parse_str($request->getUri()->getQuery(), $params);

        $limit = isset($params["limit"]) ? (int) min(100, $params["limit"]) : 50;

        $returnData = [];

        foreach ($api->getDatabase()->getAllFound($limit) as $block){
            $returnData[] = getBlockAsJSONData($api, $block, false, false);
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    if(preg_match("#^/api/block_by_(?P<by>id|height)/(?P<block>[0-9a-f]{64}|[0-9]+)(?P<kind>|/raw|/info)$#", $request->getUri()->getPath(), $matches) > 0){
        parse_str($request->getUri()->getQuery(), $params);

        $id = $matches["block"];
        $b = $matches["by"] === "id" ? $api->getDatabase()->getBlockById($id) : $api->getDatabase()->getBlockByHeight((int) $id);
        if($b === null and $matches["by"] === "id"){
            $b = $api->getDatabase()->getUncleById($id);
        }

        if($b === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }


        switch ($matches["kind"]){
            case "/raw":
                $raw = $api->getRawBlock($b->getId());
                if($raw === null){
                    return new Response(404, [
                        "Content-Type" => "application/json; charset=utf-8"
                    ], json_encode(["error" => "not_found"]));
                }

                return new Response(200, [
                    "Content-Type" => "text/plain"
                ], $raw);
            default:
                return new Response(200, [
                    "Content-Type" => "application/json; charset=utf-8"
                ], json_encode(getBlockAsJSONData($api, $b, true, isset($params["coinbase"])), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    return new Response(404);
});

$socket = new SocketServer('0.0.0.0:8080');
$server->listen($socket);
