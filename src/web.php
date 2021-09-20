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
use React\Socket\SocketServer;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;


$loader = new FilesystemLoader(__DIR__ . "/templates");
$options = [];
if(is_dir("/cache")){
    $options["cache"] = "/tmp";
}
$twig = new Environment($loader, $options);

class TwigExtraFunctions extends AbstractExtension{
    public function getFilters() {
        return [
            new TwigFilter('bcdiv', "bcdiv"),
            new TwigFilter('benc', [Utils::class, "encodeBinaryNumber"]),
            new TwigFilter('time_elapsed_string', function ($datetime, $full = false) {
                    $now = new \DateTime;
                    $ago = new \DateTime($datetime);
                    $diff = $now->diff($ago);

                    $diff->w = floor($diff->d / 7);
                    $diff->d -= $diff->w * 7;

                    $string = array(
                        'y' => 'y',
                        'm' => 'M',
                        'w' => 'w',
                        'd' => 'd',
                        'h' => 'h',
                        'i' => 'm',
                        's' => 's',
                    );
                    foreach ($string as $k => &$v) {
                        if ($diff->$k) {
                            $v = $diff->$k . $v;
                        } else {
                            unset($string[$k]);
                        }
                    }

                    if (!$full) $string = array_slice($string, 0, 1);
                    return $string ? implode(' ', $string) . ' ago' : 'just now';
                })
        ];
    }
}

$twig->addExtension(new TwigExtraFunctions());

function render(string $template, array $context = [], int $code = 200, array $headers = ["content-type" => "text/html; charset=utf-8"]){
    global $twig;

    try{
        return new Response($code, $headers, $twig->render($template, $context));
    }catch (\Exception $e){
        try{
            return new Response(500, [

            ], $twig->render("error.html", [
                "error" => [
                    "code" => 500,
                    "message" => "Internal Server Error",
                    "content" => "<pre>".htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_HTML5)."</pre>",
                ]
            ]));
        }catch (\Exception $e){
            return new Response(500, [
                "Content-Type" => "text/plain"
            ], $e->getMessage() . "\n\n" . $e->getTraceAsString());
        }
    }
}

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

            getFromAPI("pool_info")->then(function ($pool_info) use ($resolve, $headers){
                getFromAPI("found_blocks?coinbase&limit=20")->then(function ($blocks) use ($resolve, $pool_info, $headers){
                    $resolve(render("index.html", [
                        "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                        "blocks_found" => $blocks,
                        "pool" => $pool_info
                    ], 200, $headers));
                });
            });
        });
    }

    if($request->getUri()->getPath() === "/miner" and isset($params["address"])){
        if(isset($params["refresh"])){
            $headers["refresh"] = "300";
        }
        $address = $params["address"];
        return new Promise(function ($resolve, $reject) use($address, $headers){
            getFromAPI("miner_info/$address")->then(function ($miner) use ($resolve, $headers){
                if($miner !== null and isset($miner->address)){
                    getFromAPI("pool_info")->then(function ($pool_info) use ($resolve, $miner, $headers) {

                        $wsize = SIDECHAIN_PPLNS_WINDOW * 4;
                        getFromAPI("shares_in_range_window/" . $miner->id . "?from=".$pool_info->sidechain->height."&window=" . $wsize)->then(function ($shares) use ($wsize, $resolve, $miner, $pool_info, $headers) {
                            getFromAPI("payouts/" . $miner->id . "?limit=10")->then(function ($payouts) use ($wsize, $resolve, $miner, $shares, $pool_info, $headers) {
                                $count = 30 * 4;
                                $blocks_found = array_fill(0, $count, 0);
                                $uncles_found = array_fill(0, $count, 0);

                                $shares_in_window = 0;
                                $uncles_in_window = 0;

                                $tip = $pool_info->sidechain->height;
                                $wend = $tip - SIDECHAIN_PPLNS_WINDOW;

                                foreach ($shares as $s) {
                                    if(isset($s->parent)){
                                        $index = intdiv($tip - $s->parent->height, intdiv($wsize + $count - 1, $count));
                                        $uncles_found[min($index, $count - 1)]++;
                                        if($s->height > $wend){
                                            $uncles_in_window++;
                                        }
                                    }else{
                                        $index = intdiv($tip - $s->height, intdiv($wsize + $count - 1, $count));
                                        $blocks_found[min($index, $count - 1)]++;
                                        if($s->height > $wend){
                                            $shares_in_window++;
                                        }
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

                                usort($shares, function ($a, $b){
                                   return $b->timestamp - $a->timestamp;
                                });

                                $top = array_slice($shares, 0, 50);


                                $resolve(render("miner.html", [
                                    "refresh" => isset($headers["refresh"]) ? (int) $headers["refresh"] : false,
                                    "miner" => $miner,
                                    "last_shares" => $top,
                                    "last_payouts" => $payouts,
                                    "window_count" => [
                                      "blocks" => $shares_in_window,
                                      "uncles" => $uncles_in_window,
                                    ],
                                    "position" => [
                                        "blocks" => $shares_position,
                                        "uncles" => $uncles_position,
                                    ],
                                    "uncle_penalty" => SIDECHAIN_UNCLE_PENALTY
                                ], 200, $headers));
                            });
                        });
                    });
                    return;
                }

                $resolve(render("error.html", [
                    "error" => [
                        "code" => 404,
                        "message" => "Address Not Found",
                        "content" => "You need to have mined at least one share in the past."
                    ]
                ], 404));
            }, function () use($resolve){
                $resolve(render("error.html", [
                    "error" => [
                        "code" => 404,
                        "message" => "Address Not Found",
                        "content" => "You need to have mined at least one share in the past."
                    ]
                ], 404));
            });

        });

    }




    return render("error.html", [
        "error" => [
            "code" => 404,
            "message" => "Not Found"
        ]
    ], 404);
});



$socket = new SocketServer('0.0.0.0:8444');
$server->listen($socket);

Loop::get()->run();

/**
 * @param string $method
 * @return \React\Promise\PromiseInterface
 */
function getFromAPI(string $method){
    global $client;

    return $client->get("http://api:8080/api/$method")->then(function (ResponseInterface $response){
        if ($response->getStatusCode() === 200) {
            if (count($response->getHeader("content-type")) > 0 and stripos($response->getHeader("content-type")[0], "/json") !== false) {
                return json_decode($response->getBody()->getContents());
            } else {
                return $response->getBody();
            }
        } else {
            return null;
        }
    });


}