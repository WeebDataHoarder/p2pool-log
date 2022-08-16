<?php

namespace p2pool;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/constants.php";

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;


$loader = new FilesystemLoader(__DIR__ . "/templates");
$options = [];
if(is_dir("/cache")){
    $options["cache"] = "/tmp";
}
$twig = new Environment($loader, $options);

class TwigExtraFunctions extends AbstractExtension{
    public function getFilters(): array {
        return [
            new TwigFilter('gmp_init', "gmp_init"),
            new TwigFilter('gmp_intval', "gmp_intval"),
            new TwigFilter('gmp_div', "gmp_div"),
            new TwigFilter('bcdiv', "bcdiv"),
            new TwigFilter('benc', [Utils::class, "encodeBinaryNumber"]),
            new TwigFilter('henc', [Utils::class, "encodeHexBinaryNumber"]),
            new TwigFilter('time_elapsed_string', [Utils::class, "time_elapsed_string"]),
            new TwigFilter('time_diff_string', [Utils::class, "time_diff_string"]),
            new TwigFilter('time_elapsed_string_short', [Utils::class, "time_elapsed_string_short"]),
            new TwigFilter('si_units', [Utils::class, "si_units"]),
            new TwigFilter('effort_color', function ($effort){
                if($effort < 100){
                    return "#00C000";
                }else if($effort < 200){
                    return "#E0E000";
                }else{
                    return "#FF0000";
                }
            })
        ];
    }
    public function getFunctions(): array {
        return [
            new TwigFunction('getenv', 'getenv'),
        ];
    }
}

$twig->addExtension(new TwigExtraFunctions());

function render(string $template, array $context = [], int $code = 200, array $headers = ["content-type" => "text/html; charset=utf-8"]): Response {
    global $twig;

    try{
        return new Response($code, $headers, $twig->render($template, $context));
    }catch (\Throwable $e){
        try{
            return new Response(500, [

            ], $twig->render("error.html", [
                "error" => [
                    "code" => 500,
                    "message" => "Internal Server Error",
                    "content" => "<pre>".htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_HTML5)."</pre>",
                ]
            ]));
        }catch (\Throwable $e){
            return new Response(500, [
                "Content-Type" => "text/plain"
            ], $e->getMessage() . "\n\n" . $e->getTraceAsString());
        }
    }
}

function fetchReject($resolve){
    return function ($e) use($resolve){
        $resolve(render("error.html", [
            "error" => [
                "code" => 500,
                "message" => "Internal Server Error",
                "content" => "<pre>".htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_HTML5)."</pre>",
            ]
        ]));
    };
}



set_error_handler(function ($severity, $message, $filename, $lineno) {
    throw new \ErrorException($message, 0, $severity, $filename, $lineno);
});

$client = new Browser();
$server = new HttpServer(function (ServerRequestInterface $request){

    if($request->getMethod() !== "GET"){
        return new Response(403);
    }

    parse_str($request->getUri()->getQuery(), $params);
    $headers = [
        "content-type" => "text/html; charset=utf-8",
    ];


    if($request->getUri()->getPath() === "/"){

        if(isset($params["refresh"])){
            $headers["refresh"] = "120";
        }

        return new Promise(function ($resolve, $reject) use($headers) {

            getFromAPI("pool_info", 5)->then(function ($pool_info) use ($resolve, $headers){
                getFromAPI("found_blocks?coinbase&limit=20", 5)->then(function ($blocks) use ($resolve, $pool_info, $headers){
                    getFromAPI("shares?limit=20", 5)->then(function ($shares) use ($blocks, $resolve, $pool_info, $headers){
                        $resolve(render("index.html", [
                            "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                            "blocks_found" => $blocks,
                            "shares" => $shares,
                            "pool" => $pool_info
                        ], 200, $headers));
                    }, fetchReject($resolve));
                }, fetchReject($resolve));
            }, fetchReject($resolve));
        });
    }

    if($request->getUri()->getPath() === "/api"){
        return render("api.html", [], 200, $headers);
    }

    if($request->getUri()->getPath() === "/calculate-share-time"){

        return new Promise(function ($resolve, $reject) use($headers, $params) {
            getFromAPI("pool_info", 5)->then(function ($pool_info) use ($resolve, $headers, $params) {
                $hashrate = 0;
                $magnitude = 1000;
                if(isset($params["hashrate"])){
                    $hashrate = (float) $params["hashrate"];
                }
                if(isset($params["magnitude"])){
                    $magnitude = (int) $params["magnitude"];
                }
                $resolve(render("calculate-share-time.html", [
                    "pool" => $pool_info,
                    "hashrate" => $hashrate,
                    "magnitude" => $magnitude
                ], 200, $headers));
            });
        });
    }

    if($request->getUri()->getPath() === "/blocks"){

        if(isset($params["refresh"])){
            $headers["refresh"] = "600";
        }

        return new Promise(function ($resolve, $reject) use($headers) {
            getFromAPI("pool_info", 5)->then(function ($pool_info) use ($resolve, $headers) {
                getFromAPI("found_blocks?coinbase&limit=100", 30)->then(function ($blocks) use ($resolve, $pool_info, $headers) {
                    $resolve(render("blocks.html", [
                        "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                        "blocks_found" => $blocks, //TODO: load raw blocks to get their accumulated difficulty
                        "pool" => $pool_info
                    ], 200, $headers));
                }, fetchReject($resolve));
            }, fetchReject($resolve));
        });
    }

    if($request->getUri()->getPath() === "/miners"){

        if(isset($params["refresh"])){
            $headers["refresh"] = "600";
        }

        return new Promise(function ($resolve, $reject) use($headers) {
            getFromAPI("pool_info", 5)->then(function ($pool_info) use ($resolve, $headers) {
                getFromAPI("shares?onlyBlocks&limit=" . SIDECHAIN_PPLNS_WINDOW, 30)->then(function ($shares) use ($resolve, $pool_info, $headers) {
                    $miners = [];

                    $wsize = SIDECHAIN_PPLNS_WINDOW;
                    $count = 30;

                    $window_diff = gmp_init(0);

                    $tip = $pool_info->sidechain->height;
                    $wend = $tip - SIDECHAIN_PPLNS_WINDOW;

                    $tip = $shares[0];
                    foreach ($shares as $share){
                        if(!isset($miners[$share->miner])){
                            $miners[$share->miner] = (object) [
                              "weight" => gmp_init(0),
                              "shares" => array_fill(0, $count, 0),
                              "uncles" => array_fill(0, $count, 0)
                            ];
                        }

                        $index = intdiv($tip->height - $share->height, intdiv($wsize + $count - 1, $count));
                        $miners[$share->miner]->shares[$index]++;
                        $diff = gmp_init($share->weight, 16);
                        $miners[$share->miner]->weight = gmp_add($miners[$share->miner]->weight, $diff);
                        $window_diff = gmp_add($window_diff, $diff);

                        foreach ($share->uncles as $uncle){
                            if($uncle->height <= $wend){
                                continue;
                            }
                            if(!isset($miners[$uncle->miner])){
                                $miners[$uncle->miner] = (object) [
                                    "weight" => gmp_init(0),
                                    "shares" => array_fill(0, $count, 0),
                                    "uncles" => array_fill(0, $count, 0)
                                ];
                            }
                            $index = intdiv($tip->height - $uncle->height, intdiv($wsize + $count - 1, $count));
                            $miners[$uncle->miner]->uncles[$index]++;
                            $diff = gmp_init($uncle->weight, 16);
                            $miners[$uncle->miner]->weight = gmp_add($miners[$uncle->miner]->weight, $diff);
                            $window_diff = gmp_add($window_diff, $diff);
                        }
                    }

                    uasort($miners, function ($a, $b){
                       return gmp_cmp($b->weight, $a->weight);
                    });

                    foreach ($miners as $miner){
                        $shares_position = "[<";
                        foreach (array_reverse($miner->shares) as $i => $p){
                            $shares_position .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                        }
                        $shares_position .= "<]";

                        $uncles_position = "[<";
                        foreach (array_reverse($miner->uncles) as $i => $p){
                            $uncles_position .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                        }
                        $uncles_position .= "<]";

                        $miner->shares_position = $shares_position;
                        $miner->uncles_position = $uncles_position;
                    }



                    $resolve(render("miners.html", [
                        "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                        "miners" => $miners,
                        "tip" => $tip,
                        "pool" => $pool_info
                    ], 200, $headers));
                }, fetchReject($resolve));
            }, fetchReject($resolve));
        });
    }

    if(preg_match("#^/share/(?P<block>[0-9a-f]{64}|[0-9]+)$#", $request->getUri()->getPath(), $matches) > 0){
        $identifier = $matches["block"];
        $k = preg_match("#^[0-9a-f]{64}$#", $identifier) > 0 ? "id" : "height";
        return new Promise(function ($resolve, $reject) use($k, $identifier, $headers) {
            getFromAPI("pool_info", 5)->then(function ($pool_info) use ($k, $identifier, $resolve, $headers){
                getFromAPI("block_by_$k/$identifier?coinbase")->then(function ($block) use ($pool_info, $k, $identifier, $resolve, $headers) {
                    getFromAPI("block_by_$k/$identifier/raw")->then(function ($rawBlock) use ($resolve, $pool_info, $headers, $block) {
                        $raw = null;
                        try{
                            $raw = BinaryBlock::fromHexDump($rawBlock);
                        }catch (\Throwable $e){

                        }

                        $resolve(render("share.html", [
                            "pool" => $pool_info,
                            "block" => $block,
                            "raw" => $raw
                        ], 200, $headers));
                    }, function ($e) use ($resolve, $pool_info, $headers, $block){
                        $resolve(render("share.html", [
                            "pool" => $pool_info,
                            "block" => $block,
                            "raw" => null
                        ], 200, $headers));
                    });
                }, function ($e) use ($resolve){
                    $resolve(render("error.html", [
                        "error" => [
                            "code" => 404,
                            "message" => "Block Not Found"
                        ]
                    ], 404));
                });
            }, fetchReject($resolve));
        });
    }

    if(($request->getUri()->getPath() === "/miner" and isset($params["address"])) or preg_match("#^/miner/(?P<miner>[0-9]+|4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)$#", $request->getUri()->getPath(), $matches) > 0){
        if(isset($params["refresh"])){
            $headers["refresh"] = "300";
        }
        $address = $params["address"] ?? $matches["miner"];
        return new Promise(function ($resolve, $reject) use($address, $headers){
            getFromAPI("miner_info/$address")->then(function ($miner) use ($resolve, $headers){
                if($miner !== null and isset($miner->address)){
                    getFromAPI("pool_info", 5)->then(function ($pool_info) use ($resolve, $miner, $headers) {

                        $wsize = SIDECHAIN_PPLNS_WINDOW * 4;
                        getFromAPI("shares_in_window/" . $miner->id . "?from=".$pool_info->sidechain->height."&window=" . $wsize)->then(function ($shares) use ($wsize, $resolve, $miner, $pool_info, $headers) {
                            getFromAPI("payouts/" . $miner->id . "?limit=10")->then(function ($payouts) use ($wsize, $resolve, $miner, $shares, $pool_info, $headers) {
                                getFromAPI("shares?limit=50&miner=" .$miner->id)->then(function ($lastshares) use ($payouts, $wsize, $resolve, $miner, $shares, $pool_info, $headers){

                                    $count = 30 * 4;
                                    $blocks_found = array_fill(0, $count, 0);
                                    $uncles_found = array_fill(0, $count, 0);

                                    $shares_in_window = 0;
                                    $uncles_in_window = 0;
                                    $long_diff = gmp_init(0);
                                    $window_diff = gmp_init(0);

                                    $tip = $pool_info->sidechain->height;
                                    $wend = $tip - SIDECHAIN_PPLNS_WINDOW;

                                    foreach ($shares as $s) {
                                        if(isset($s->parent)){
                                            $index = intdiv($tip - $s->parent->height, intdiv($wsize + $count - 1, $count));
                                            $uncles_found[min($index, $count - 1)]++;
                                            if($s->height > $wend){
                                                $uncles_in_window++;
                                                $window_diff = gmp_add($window_diff, $s->weight);
                                            }
                                            $long_diff = gmp_add($long_diff, $s->weight);
                                        }else{
                                            $index = intdiv($tip - $s->height, intdiv($wsize + $count - 1, $count));
                                            $blocks_found[min($index, $count - 1)]++;
                                            if($s->height > $wend){
                                                $shares_in_window++;
                                                $window_diff = gmp_add($window_diff, $s->weight);
                                            }
                                            $long_diff = gmp_add($long_diff, $s->weight);
                                        }
                                    }

                                    $shares_position = "[<";
                                    foreach (array_reverse($blocks_found) as $i => $p){
                                        if($i === (30 * 3)){
                                            $shares_position .= "|";
                                        }
                                        $shares_position .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                                    }
                                    $shares_position .= "<]";

                                    $uncles_position = "[<";
                                    foreach (array_reverse($uncles_found) as $i => $p){
                                        if($i === (30 * 3)){
                                            $uncles_position .= "|";
                                        }
                                        $uncles_position .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                                    }
                                    $uncles_position .= "<]";

                                    $resolve(render("miner.html", [
                                        "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                                        "pool" => $pool_info,
                                        "miner" => $miner,
                                        "last_shares" => $lastshares,
                                        "last_payouts" => $payouts,
                                        "window_weight" => gmp_intval($window_diff),
                                        "weight" => gmp_intval($long_diff),
                                        "window_count" => [
                                            "blocks" => $shares_in_window,
                                            "uncles" => $uncles_in_window,
                                        ],
                                        "count" => [
                                            "blocks" => array_sum($blocks_found),
                                            "uncles" => array_sum($uncles_found),
                                        ],
                                        "position" => [
                                            "blocks" => $shares_position,
                                            "uncles" => $uncles_position,
                                        ],
                                    ], 200, $headers));
                                });
                            });
                        });
                    });
                    return;
                }

                $resolve(render("error.html", [
                    "error" => [
                        "code" => 404,
                        "message" => "Address Not Found",
                        "content" => "<div class=\"center\" style=\"text-align: center\">You need to have mined at least one share in the past. Come back later :)</div>"
                    ]
                ], 404));
            }, function () use($resolve){
                $resolve(render("error.html", [
                    "error" => [
                        "code" => 404,
                        "message" => "Address Not Found",
                        "content" => "<div class=\"center\" style=\"text-align: center\">You need to have mined at least one share in the past. Come back later :)</div>"
                    ]
                ], 404));
            });

        });
    }




    return render("error.html", [
        "error" => [
            "code" => 404,
            "message" => "Page Not Found"
        ]
    ], 404);
});

$socket = new SocketServer('0.0.0.0:8444');
$server->listen($socket);

Loop::get()->run();

$apiCache = [];

/**
 * @param string $method
 * @param int $cacheTime
 * @return PromiseInterface
 */
function getFromAPI(string $method, int $cacheTime = 0): PromiseInterface {
    global $client;
    global $apiCache;

    if($cacheTime > 0 and isset($apiCache[$method]) and ($apiCache[$method][0] + $cacheTime) >= time()){
        $v = $apiCache[$method][1];
        return new Promise(function ($resolve, $reject) use ($v){
            $resolve($v);
        });
    }

    return $client->get("http://api:8080/api/$method")->then(function (ResponseInterface $response) use($cacheTime, $method){
        global $apiCache;
        if ($response->getStatusCode() === 200) {
            if (count($response->getHeader("content-type")) > 0 and stripos($response->getHeader("content-type")[0], "/json") !== false) {
                $result = json_decode($response->getBody()->getContents());
            } else {
                $result = $response->getBody()->getContents();
            }

            if($cacheTime > 0){
                $apiCache[$method] = [time(), $result];
            }
            return $result;
        } else {
            return null;
        }
    });


}