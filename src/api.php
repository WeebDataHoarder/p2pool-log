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
        $tx = $b->isMainFound() ? $api->getDatabase()->getCoinbaseTransaction($b) : null;
        $data["coinbase"]["payouts"] = [];
        if($b->isMainFound() and $tx !== null){
            foreach ($tx->getOutputs() as $output){
                $data["coinbase"]["payouts"][$output->getIndex()] = [
                    "amount" => $output->getAmount(),
                    "index" => $output->getIndex(),
                    "address" => ($miner = $api->getDatabase()->getMiner($output->getMiner()))->getAddress(),
                    //"public_key" => $tx::getEphemeralPublicKey($tx, $miner, $output->getIndex());
                ];
            }
        }else{
            $payouts = $api->getWindowPayouts($b->getHeight(), $b->getCoinbaseReward() === 0 ? null : $b->getCoinbaseReward());
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

    //Use this to provide unprettified json
    $isKnownBrowser = count($request->getHeader("user-agent")) > 0 and preg_match("#(mozilla)#i", $request->getHeader("user-agent")[0]) > 0;

    if(preg_match("#^/api/pool_info$#", $request->getUri()->getPath(), $matches) > 0){
        $tip = $api->getDatabase()->getChainTip();


        $block_count = 0;
        $uncle_count = 0;
        $miners = [];
        foreach ($api->getDatabase()->getBlocksInWindow($tip->getHeight()) as $b){
            $block_count++;
            @$miners[$b->getMiner()]++;
            foreach ($api->getDatabase()->getUnclesByParentId($b->getId()) as $u){
                if($tip->getHeight() - $u->getHeight() > SIDECHAIN_PPLNS_WINDOW){ //TODO: check this check is correct :)
                    continue;
                }
                ++$uncle_count;
                @$miners[$u->getMiner()]++;
            }
        }

        $returnData = [
            "sidechain" => [
                "id" => $tip->getId(),
                "height" => $tip->getHeight(),
                "difficulty" => $tip->getDifficulty(),
                "timestamp" => $tip->getTimestamp(),
                "window" => [
                    "miners" => count($miners),
                    "blocks" => $block_count,
                    "uncles" => $uncle_count,
                ]
            ],
            "mainchain" => [
                "id" => $tip->getMinerMainId(),
                "height" => $tip->getMainHeight() - 1,
                "difficulty" => $tip->getMinerMainDifficulty()
            ]
        ];

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
    }

    if(preg_match("#^/api/miner_info/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null and preg_match("#^[0-9]+$#", $matches["miner"]) > 0){
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
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
    }

    if(preg_match("#^/api/shares_in_range_window/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null and preg_match("#^[0-9]+$#", $matches["miner"]) > 0){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }

        parse_str($request->getUri()->getQuery(), $params);

        $window = isset($params["window"]) ? (int) min(SIDECHAIN_PPLNS_WINDOW * 4, $params["window"]) : SIDECHAIN_PPLNS_WINDOW;
        $from = isset($params["from"]) ? (int) max(0, $params["from"]) : null;

        $returnData = [

        ];

        foreach ($api->getDatabase()->getBlocksByMinerIdInWindow($miner->getId(), $from, $window) as $block){
            $r = [
                "id" => $block->getId(),
                "height" => $block->getHeight(),
                "timestamp" => $block->getTimestamp(),
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
                "timestamp" => $uncle->getTimestamp(),
                "weight" => gmp_intval(gmp_div(gmp_mul(gmp_init($uncle->getDifficulty(), 16), 100 - SIDECHAIN_UNCLE_PENALTY), 100))
            ];
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
    }

    if(preg_match("#^/api/shares_in_current_window/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null and preg_match("#^[0-9]+$#", $matches["miner"]) > 0){
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
                "timestamp" => $block->getTimestamp(),
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
                "timestamp" => $block->getTimestamp(),
                "weight" => gmp_intval(gmp_div(gmp_mul(gmp_init($uncle->getDifficulty(), 16), 100 - SIDECHAIN_UNCLE_PENALTY), 100))
            ];
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
    }

    if(preg_match("#^/api/payouts/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $miner = (strlen($matches["miner"]) > 10 and $matches["miner"][0] === "4") ? $api->getDatabase()->getMinerByAddress($matches["miner"]) : null;
        if($miner === null and preg_match("#^[0-9]+$#", $matches["miner"]) > 0){
            $miner = $api->getDatabase()->getMiner((int) $matches["miner"]);
        }
        if($miner === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }


        $top_limit = iterator_to_array($api->getDatabase()->query("SELECT COUNT(*) as count FROM coinbase_outputs WHERE miner = $1;", [$miner->getId()]))[0]["count"];
        $limit = isset($params["search_limit"]) ? (int) min($top_limit, $params["search_limit"]) : min($top_limit, 10);

        //TODO: refactor this to use different query
        $returnData = [];
        foreach ($api->getDatabase()->getAllFound(2000) as $block){
            if(count($returnData) >= $limit){
                break;
            }

            $o = $api->getDatabase()->getCoinbaseTransactionOutputByMinerId($block->getCoinbaseId(), $miner->getId());

            if($o === null){
                continue;
            }

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
                    "reward" => $o->getAmount(),
                    "private_key" => $block->getCoinbasePrivkey(),
                    "index" => $o->getIndex()
                ],
            ];
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
    }

    if(preg_match("#^/api/redirect/block/(?P<main_height>[0-9]+|.?[0-9A-Za-z]+)$#", $request->getUri()->getPath(), $matches) > 0){
        return new Response(302, [
            "Location" => "https://xmrchain.net/block/".Utils::decodeBinaryNumber($matches["main_height"])
        ]);
    }

    if(preg_match("#^/api/redirect/coinbase/(?P<height>[0-9]+|.?[0-9A-Za-z]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $b = $api->getDatabase()->getBlockByHeight(Utils::decodeBinaryNumber($matches["height"]));
        if($b === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }
        return new Response(302, [
            "Location" => "https://xmrchain.net/tx/".$b->getCoinbaseId()
        ]);
    }

    if(preg_match("#^/api/redirect/prove/(?P<height_index>[0-9]+|.[0-9A-Za-z]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $i = Utils::decodeBinaryNumber($matches["height_index"]);
        $n = ceil(log(SIDECHAIN_PPLNS_WINDOW * 4, 2));
        $height = $i >> $n;
        $index = $i & ((1 << $n) - 1);

        $b = $api->getDatabase()->getBlockByHeight($height);

        if($b === null or ($tx = $api->getDatabase()->getCoinbaseTransactionOutputByIndex($b->getCoinbaseId(), $index)) === null){
            return new Response(404, [
                "Content-Type" => "application/json; charset=utf-8"
            ], json_encode(["error" => "not_found"]));
        }
        $miner = $api->getDatabase()->getMiner($tx->getMiner());
        return new Response(302, [
            //TODO: make own viewer
            "Location" => "https://www.exploremonero.com/receipt/".$b->getCoinbaseId()."/".$miner->getAddress()."/".$b->getCoinbasePrivkey()
        ]);
    }

    if(preg_match("#^/api/redirect/prove/(?P<height>[0-9]+|.[0-9A-Za-z]+)/(?P<miner>[0-9]+|.?[0-9A-Za-z]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $b = $api->getDatabase()->getBlockByHeight(Utils::decodeBinaryNumber($matches["height"]));
        $miner = $api->getDatabase()->getMiner(Utils::decodeBinaryNumber($matches["miner"]));
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
            $returnData[] = getBlockAsJSONData($api, $block, false, isset($params["coinbase"]));
        }

        return new Response(200, [
            "Content-Type" => "application/json; charset=utf-8"
        ], json_encode($returnData, JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
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
                ], json_encode(getBlockAsJSONData($api, $b, true, isset($params["coinbase"])), JSON_UNESCAPED_SLASHES | ($isKnownBrowser ? JSON_PRETTY_PRINT : 0)));
        }
    }

    return new Response(404, [
        "Content-Type" => "application/json; charset=utf-8"
    ], json_encode(["error" => "method_not_found"]));
});

$socket = new SocketServer('0.0.0.0:8080');
$server->listen($socket);