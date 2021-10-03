<?php

namespace p2pool;

use p2pool\db\Block;
use p2pool\db\Database;
use p2pool\db\Miner;
use p2pool\db\Subscription;
use p2pool\db\UncleBlock;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/constants.php";

$api = new P2PoolAPI(new Database($argv[1]), "/api");
$database = $api->getDatabase();

foreach (["IRC_SERVER_HOST", "IRC_SERVER_PORT", "IRC_SERVER_PASS", "BOT_BLOCKS_FOUND_CHANNEL", "BOT_COMMANDS_CHANNEL", "BOT_NICK", "BOT_USER", "BOT_PASSWORD",] as $c) {
    define($c, getenv($c));
}

setlocale(LC_CTYPE, "en_US.UTF-8");
date_default_timezone_set('UTC');

const FORMAT_COLOR_GREEN = "\x0303";
const FORMAT_COLOR_RED = "\x0304";
const FORMAT_COLOR_ORANGE = "\x0307";
const FORMAT_COLOR_YELLOW = "\x0308";
const FORMAT_COLOR_LIGHT_GREEN = "\x0309";
const FORMAT_BOLD = "\x02";
const FORMAT_ITALIC = "\x1D";
const FORMAT_UNDERLINE = "\x1F";
const FORMAT_RESET = "\x0F";

const PERMISSION_NONE = 0;
const DEFAULT_PERMISSION = PERMISSION_NONE;


$_ircMessageQueue = [];
$_ircLastMessage = microtime(true);
function handleIRCMessageQueue(){
    global $_ircLastMessage, $_ircMessageQueue, $socket;

    $intervalRequired = 1.0;

    if(count($_ircMessageQueue) > 0 and microtime(true) >= ($_ircLastMessage + $intervalRequired)){
        $line = array_shift($_ircMessageQueue);
        echo "[RAWOUT ASYNC] $line\n";
        fwrite($socket, "$line\r\n");
        fflush($socket);
        $_ircLastMessage = microtime(true);
    }
}

function sendIRCMessage($message, $to, $async = false){
    global $socket;
    global $_ircMessageQueue, $_ircLastMessage;
    $cmd = "PRIVMSG";
    $message = str_replace(["\r", "\n", "\\r", "\\n"], "", $message);

    $line = "$cmd $to :$message";

    if(!$async){
        echo "[RAWOUT] $line\n";
        fwrite($socket, "$line\r\n");
        fflush($socket);
        $_ircLastMessage = microtime(true);
    }else{
        $_ircMessageQueue[] = $line;
        handleIRCMessageQueue();
    }
}

function removePing($s){
    $groupMatch = " \t:\\-_,\"'=\\)\\(\\.\\/#@<>";
    return preg_replace("/([^$groupMatch]{1})([^$groupMatch]+)([$groupMatch]|$)/iu", "$1\u{FEFF}$2$3", $s);
}
function cleanupCodes($text){
    return preg_replace('/[\r\n\t]|[\x02\x0F\x16\x1D\x1F]|\x03(\d{,2}(,\d{,2})?|(\x9B|\x1B\[)[0-?]*[ -\/]*[@-~])?/u', "",  $text);
}


function handleNewJoin($sender, $senderCloak, $channel){

}

function handleNewCTCP($sender, $senderCloak, $to, $message){
    if($to === BOT_NICK){
        $answer = $sender;
    }else{
        $answer = $to;
    }

    switch ($message){
        case "VERSION":
            sendIRCMessage("\x01" . BOT_NICK . "\x01", $answer, true);
            break;
    }
}


function handleNewMessage($sender, $senderCloak, $to, $message, $isAction = false) {
    global $database;
    $message = cleanupCodes(str_replace(["“", "”", '１', '２', '３', '４', '５', '６', '７', '８', '９', '０'], ["\"", "\"", '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], preg_replace("/(\u{200B}|\u{FEFF})/u", "", trim($message))));

    $originalSender = [
        "id" => strtolower($sender),
        "user" => $sender,
        "name" => $sender,
        "mask" => $senderCloak,
        "record" => null
    ];

    $currentPermissions = DEFAULT_PERMISSION;

    $to = strtolower($to);


    $commands = [
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(sub|subscribe)[ \t]+(4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database, $api;
                $maddress = null;
                try{
                    $maddress = new MoneroAddress($matches[2]);
                    if(!$maddress->verify()){
                        sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                        return;
                    }
                }catch (\Throwable $e){
                    sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                    return;
                }

                $miner = $database->getOrCreateMinerByAddress($maddress->getAddress());
                $sub = new Subscription($miner->getId(), $originalSender["user"]);
                $database->addSubscription($sub);
                sendIRCMessage("Subscribed your nick to shares found by " . FORMAT_ITALIC . shortenAddress($maddress->getAddress()) . ". You can private message this bot for any commands instead of using public channels.", $answer);

                sendIRCMessage("Your miner statistics https://p2pool.observer/m/" . Utils::encodeBinaryNumber($miner->getId()), $answer, true);
                $payouts = $api->getWindowPayouts();

                $myReward = (($payouts[$miner->getId()] ?? 0) / array_sum($payouts));
                if($myReward > NOTIFICATION_POOL_SHARE){
                    sendIRCMessage("You have more than ".round(NOTIFICATION_POOL_SHARE * 100, 2)."% of the pool's current hashrate. Share notifications will not be sent above this threshold.", $answer, true);
                }

            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(unsub|unsubscribe)[ \t]+(4[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database;
                $maddress = null;
                try{
                    $maddress = new MoneroAddress($matches[2]);
                    if(!$maddress->verify()){
                        sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                        return;
                    }
                }catch (\Throwable $e){
                    sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                    return;
                }

                $miner = $database->getOrCreateMinerByAddress($maddress->getAddress());
                $sub = new Subscription($miner->getId(), $originalSender["user"]);
                $database->removeSubscription($sub);
                sendIRCMessage("Unsubscribed your nick to shares found by " . FORMAT_ITALIC . shortenAddress($maddress->getAddress()), $answer);
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(last|block|lastblock|pool)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database, $api;

                $block = $database->getLastFound();
                $payouts = $api->getWindowPayouts($block->getHeight());
                $tip = $database->getChainTip();

                $diff = gmp_init($tip->getDifficulty(), 16);
                $global_diff = gmp_init($tip->getMinerMainDifficulty(), 16);
                $hashrate = gmp_div($diff, SIDECHAIN_BLOCK_TIME);
                $global_hashrate = gmp_div($global_diff, MAINCHAIN_BLOCK_TIME);

                /** @var Block[] $blocks */
                $blocks = iterator_to_array($database->getBlocksByQuery('WHERE height > $1 ORDER BY height ASC', [$tip->getHeight() - (15 * 60) / SIDECHAIN_BLOCK_TIME]));
                $timeDiff = end($blocks)->getTimestamp() - reset($blocks)->getTimestamp();
                $expectedTime = count($blocks) * SIDECHAIN_BLOCK_TIME;
                $adjustement = ($expectedTime / $timeDiff) * 1000000;
                $adjusted_diff = gmp_div(gmp_mul($diff, (int) $adjustement), 1000000);
                $short_hashrate = gmp_div($adjusted_diff, SIDECHAIN_BLOCK_TIME);
                /*$cummDiff = gmp_init(0);
                foreach ($blocks as $b){
                    $cummDiff = gmp_add($cummDiff, gmp_init($b->getProofDifficulty(), 16));
                }*/

                $current_effort = gmp_intval(gmp_div(gmp_mul(gmp_sub($api->getPoolStats()->pool_statistics->totalHashes, $api->getPoolBlocks()[0]->totalHashes), 100000), $global_diff)) / 1000;

                $effort = FORMAT_BOLD;
                if($current_effort <= 0){
                    $current_effort = 0;
                }
                if($current_effort < 100){
                    $effort .= FORMAT_COLOR_LIGHT_GREEN;
                }else if($current_effort < 200){
                    $effort .= FORMAT_COLOR_YELLOW;
                }else{
                    $effort .= FORMAT_COLOR_RED;
                }

                $effort .= round($current_effort, 2) . "%" . FORMAT_RESET;

                $uHeight = ($block->getHeight() << 16) | hexdec(substr($block->getId(), 0, 4));
                sendIRCMessage("Last block found at height " . FORMAT_COLOR_RED . $block->getMainHeight() . FORMAT_RESET . " ".Utils::time_elapsed_string("@" . $block->getTimestamp()).", ".date("Y-m-d H:i:s", $block->getTimestamp())." UTC :: https://p2pool.observer/s/" . Utils::encodeBinaryNumber($uHeight) . " :: ".FORMAT_COLOR_ORANGE . count($payouts)." miners" . FORMAT_RESET . " paid for ".FORMAT_COLOR_ORANGE . FORMAT_BOLD . bcdiv((string) $block->getCoinbaseReward(), "1000000000000", 12) . " XMR".FORMAT_RESET." :: Current effort $effort :: Pool height ". $tip->getHeight() ." :: Pool hashrate ".Utils::si_units(gmp_intval($hashrate))."H/s (short-term ".Utils::si_units(gmp_intval($short_hashrate), 1)."H/s) :: Global hashrate ".Utils::si_units(gmp_intval($global_hashrate))."H/s", $answer);
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(payout|payment|last\-payment)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database, $api;

                $subs = $database->getSubscriptionsFromNick($originalSender["user"]);

                if($subs === null){
                    sendIRCMessage("No known subscriptions to your nick.", $answer);
                    return;
                }


                $miners = [];
                foreach ($subs as $sub) {
                    $m = $database->getMiner($sub->getMiner());
                    $miners[$m->getId()] = $m;
                }

                $c = 0;
                foreach ($database->getAllFound(60) as $block){
                    /** @var Block $block */
                    ++$c;

                    $total = 0;
                    $minerAmount = [];
                    foreach ($miners as $miner){
                        $o = $database->getCoinbaseTransactionOutputByMinerId($block->getCoinbaseId(), $miner->getId());
                        if($o !== null){
                            $total += $o->getAmount();
                            $minerAmount[$o->getIndex()] = $o->getAmount();
                        }
                    }

                    if($total !== 0){
                        arsort($minerAmount);
                        reset($minerAmount);
                        $index = key($minerAmount);
                        $total = bcdiv((string) $total, "1000000000000", 12);

                        $i = ($block->getHeight() << (int) ceil(log(SIDECHAIN_PPLNS_WINDOW * 4, 2))) | $index;

                        sendIRCMessage("Your last payout was ". FORMAT_COLOR_ORANGE . FORMAT_BOLD . $total . " XMR".FORMAT_RESET." on block ". FORMAT_COLOR_RED . $block->getMainHeight() . FORMAT_RESET ." ".Utils::time_elapsed_string("@" . $block->getTimestamp()).", ".date("Y-m-d H:i:s", $block->getTimestamp())." UTC :: https://p2pool.observer/s/".$block->getHeight()." :: Verify payout https://p2pool.observer/p/".Utils::encodeBinaryNumber($i), $answer);
                        return;
                    }
                }


                sendIRCMessage("No known payouts to your subscriptions in the last ".$c." mined blocks.", $answer);
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(status|shares)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database, $api;

                $subs = $database->getSubscriptionsFromNick($originalSender["user"]);
                $total = null;

                $payouts = $api->getWindowPayouts();
                $myReward = 0;

                foreach ($subs as $sub){
                    $result = getShareWindowPosition($sub->getMiner());
                    $myReward += $payouts[$sub->getMiner()] ?? 0;
                    $miners[$sub->getMiner()] = $payouts[$sub->getMiner()] ?? 0;
                    if($total === null){
                        $total = $result;
                    }else{
                        foreach ($total[0] as $i => $v){
                            $total[0][$i] += $result[0][$i];
                        }
                        foreach ($total[1] as $i => $v){
                            $total[1][$i] += $result[1][$i];
                        }
                    }
                }

                if($total === null){
                    sendIRCMessage("No known subscriptions to your nick.", $answer);
                    return;
                }

                $tip = $database->getChainTip();
                $hashrate = gmp_div(gmp_strval(gmp_init($tip->getDifficulty(), 16)), SIDECHAIN_BLOCK_TIME);

                $share_count = array_sum($total[0]);
                $uncle_count = array_sum($total[1]);

                $myReward = ($myReward / array_sum($payouts));

                $myHashrate = gmp_strval($hashrate) * $myReward;
                $myReward = (string) round($myReward * 100, 3);

                arsort($miners);

                reset($miners);
                $minerId = key($miners);

                $m = "Your shares $share_count (+$uncle_count uncles) ~$myReward% " . Utils::si_units($myHashrate) . "H/s :: Statistics https://p2pool.observer/m/" . Utils::encodeBinaryNumber($minerId);

                if($share_count > 0){
                    $m .= " :: Shares position [";
                    foreach ($total[0] as $p){
                        $m .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                    }
                    $m .= "]";
                }

                if($uncle_count > 0){
                    $m .= " :: Uncles position [";
                    foreach ($total[1] as $p){
                        $m .= ($p > 0 ? ($p > 9 ? "+" : (string) $p) : ".");
                    }
                    $m .= "]";
                }
                sendIRCMessage($m, $answer);
            },
        ],
    ];

    if($to === BOT_NICK){
        $answer = $sender;
    }else{
        $answer = $to;
    }


    foreach($commands as $cmd){
        if($currentPermissions >= $cmd["permission"] and in_array(strtolower($to), $cmd["targets"], true) and preg_match($cmd["match"], $message, $matches) > 0){
            $cmd["command"]($originalSender, $answer, $to, $matches);
            break;
        }
    }

}

function shortenAddress(string $address) : string {
    return substr($address, 0, 10) . "..." . substr($address, -10);
}

function blockFoundMessage(Block $b){
    global $api;
    $payouts = $api->getWindowPayouts();

    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "BLOCK FOUND:" . FORMAT_RESET . " height " . FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " :: Pool height ". $b->getHeight() ." :: https://p2pool.observer/s/" . $b->getHeight() . " :: ".FORMAT_COLOR_ORANGE . count($payouts)." miners paid" . FORMAT_RESET . " :: Id " . FORMAT_ITALIC . $b->getMainId(), BOT_BLOCKS_FOUND_CHANNEL, true);
    sendIRCMessage("Paid ".FORMAT_COLOR_ORANGE . FORMAT_BOLD . bcdiv((string) $b->getCoinbaseReward(), "1000000000000", 12) . " XMR".FORMAT_RESET." :: Verify payouts using Tx private key " . FORMAT_ITALIC . $b->getCoinbasePrivkey() . FORMAT_RESET . " :: Payout transaction for block ". FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " https://p2pool.observer/c/".Utils::encodeBinaryNumber($b->getHeight())."", BOT_BLOCKS_FOUND_CHANNEL, true);
    sleep(1);
}

function blockUnfoundMessage(Block $b){
    sendIRCMessage(FORMAT_COLOR_RED . FORMAT_BOLD . "BLOCK ORPHANED:" . FORMAT_RESET . " height " . FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " :: Pool height ". $b->getHeight() ." :: Pool Id " . FORMAT_ITALIC . $b->getId() . FORMAT_RESET . " :: Id " . FORMAT_ITALIC . $b->getMainId(), BOT_BLOCKS_FOUND_CHANNEL, true);
}

function getShareWindowPosition(int $miner, int $count = 30): array {
    global $database;

    $tip = $database->getChainTip();

    $blocks_found = array_fill(0, $count, 0);
    $uncles_found = array_fill(0, $count, 0);

    foreach ($database->getBlocksByMinerIdInWindow($miner) as $b){
        $index = intdiv($tip->getHeight() - $b->getHeight(), intdiv(SIDECHAIN_PPLNS_WINDOW + $count - 1, $count));
        $blocks_found[min($index, $count - 1)]++;
    }

    foreach ($database->getUnclesByMinerIdInWindow($miner) as $b){
        $index = intdiv($tip->getHeight() - $b->getParentHeight(), intdiv(SIDECHAIN_PPLNS_WINDOW + $count - 1, $count));
        $uncles_found[min($index, $count - 1)]++;
    }

    return [$blocks_found, $uncles_found];
}

function shareFoundMessage(Block $b, Subscription $sub, Miner $miner, array $uncles = []){
    global $api;
    $payouts = $api->getWindowPayouts();

    $myReward = (($payouts[$miner->getId()] ?? 0) / array_sum($payouts));
    if($myReward > NOTIFICATION_POOL_SHARE and !$b->isMainFound()){ //Disable notifications with more than 20% of hashrate
        return;
    }
    $myReward = (string) round($myReward * 100, 3);
    $positions = getShareWindowPosition($miner->getId());

    $share_count = array_sum($positions[0]);
    $uncle_count = array_sum($positions[1]);
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "SHARE FOUND:" . FORMAT_RESET . " Pool height " . FORMAT_COLOR_RED . $b->getHeight() . FORMAT_RESET . " ".(count($uncles) > 0 ? ":: Includes " . count($uncles) . " uncle(s) for extra ".SIDECHAIN_UNCLE_PENALTY."% of their value " : "").($b->isMainFound() ? ":: ".FORMAT_BOLD. FORMAT_COLOR_LIGHT_GREEN ." MINED MAINCHAN BLOCK " . $b->getMainHeight() . FORMAT_RESET . " " : "").":: Your shares $share_count (+$uncle_count uncles) ~$myReward% :: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick(), true);
}

function uncleFoundMessage(UncleBlock $b, Subscription $sub, Miner $miner){
    global $api;
    $payouts = $api->getWindowPayouts();

    $myReward = (($payouts[$miner->getId()] ?? 0) / array_sum($payouts));
    if($myReward > NOTIFICATION_POOL_SHARE and !$b->isMainFound()){ //Disable notifications with more than 20% of hashrate
        return;
    }
    $myReward = (string) round($myReward * 100, 3);
    $positions = getShareWindowPosition($miner->getId());

    $share_count = array_sum($positions[0]);
    $uncle_count = array_sum($positions[1]);
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "UNCLE SHARE FOUND:" . FORMAT_RESET . " Pool height " . FORMAT_COLOR_RED . $b->getParentHeight() . FORMAT_RESET . " ".($b->isMainFound() ? ":: ".FORMAT_BOLD. FORMAT_COLOR_LIGHT_GREEN ." MINED MAINCHAN BLOCK " . $b->getMainHeight() . FORMAT_RESET . " " : "").":: Accounted for ".(100 - SIDECHAIN_UNCLE_PENALTY)."% of value :: Your shares $share_count (+$uncle_count uncles) ~$myReward% :: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick(), true);
}

function getString($str, $start, $end){
    $str = strstr($str, $start, false);
    return substr($str, strlen($start), strpos($str, $end) - strlen($start));
}

$checks = [
    [
        "every" => 60,
        "last" => 0,
        "f" => function(){
            global $xvb_raffle, $database;
            if(!isset($xvb_raffle)){
                $xvb_raffle = null;
            }

            $ch = curl_init("https://xmrvsbeast.com/p2pool/stats");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            //curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            //curl_setopt($ch, CURLOPT_PROXY, "tor:9050");
            $ret = json_decode(curl_exec($ch));

            if(isset($ret->winner)){
                $timeRemains = (int) $ret->time_remain;
                $players = (int) $ret->players;
                $hashRate = $ret->bonus_hr * 1000;
                $addr = explode("...", $ret->winner);

                if(count($addr) === 2){
                    $miner = $database->getMinerByAddressBounds($addr[0], $addr[1]);

                    if($miner !== null and $xvb_raffle !== $miner->getId()){
                        if($xvb_raffle === null){
                            $xvb_raffle = $miner->getId();
                            return;
                        }
                        $xvb_raffle = $miner->getId();

                        foreach ($database->getSubscriptionsFromMiner($miner->getId()) as $sub){
                            sendIRCMessage("You have been selected for XvB P2Pool Bonus Hash Rate Raffle :: Remaining $timeRemains minutes :: Bonus ".Utils::si_units($hashRate, 2)."H/s :: Currently $players players :: https://xmrvsbeast.com/p2pool/ :: Payout address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick(), true);
                        }
                    }
                }
            }


        }
    ]
];

$foundBlocks = iterator_to_array($database->getAllFound(6));

$lastTip = null;
function handleCheck(){
    global $lastTip, $database, $foundBlocks;


    foreach ($foundBlocks as $i => $block){
        $block_db = $database->getBlockById($block->getId());
        if($block_db !== null and $block_db->isMainFound()){
            continue;
        }

        $uncle_db = $database->getUncleById($block->getId());
        if($uncle_db !== null and $uncle_db->isMainFound()){
            continue;
        }

        blockUnfoundMessage($block);
        unset($foundBlocks[$i]);
    }

    if($lastTip === null){
        $lastTip = $database->getChainTip();
    }

    $newTip = $database->getChainTip();

    if($lastTip->getId() === $newTip->getId() and $lastTip->isMainFound() === false and $newTip->isMainFound() === true){
        blockFoundMessage($newTip);
    }

    for($h = $lastTip->getHeight() + 1; $h <= $newTip->getHeight(); ++$h){
        $b = $database->getBlockByHeight($h);
        if($b->isMainFound()){
            $blockExists = false;
            foreach ($foundBlocks as $block){
                if($block->getMainId() === $b->getMainId()){
                    $blockExists = true;
                    break;
                }
            }

            if(!$blockExists){
                blockFoundMessage($b);
                array_unshift($foundBlocks, $b);
                if(count($foundBlocks) > 6){
                    array_pop($foundBlocks);
                }
            }
        }

        $uncles = iterator_to_array($database->getUnclesByParentId($b->getId()));

        $miner = $database->getMiner($b->getMiner());
        foreach ($database->getSubscriptionsFromMiner($miner->getId()) as $sub){
            shareFoundMessage($b, $sub, $miner, $uncles);
        }

        foreach ($uncles as $uncle){
            if($uncle->isMainFound()){
                $blockExists = false;
                foreach ($foundBlocks as $block){
                    if($block->getMainId() === $uncle->getMainId()){
                        $blockExists = true;
                        break;
                    }
                }

                if(!$blockExists){
                    blockFoundMessage($uncle);
                    array_unshift($foundBlocks, $uncle);
                    if(count($foundBlocks) > 6){
                        array_pop($foundBlocks);
                    }
                }
            }

            $uncle_miner = $database->getMiner($uncle->getMiner());
            foreach ($database->getSubscriptionsFromMiner($uncle_miner->getId()) as $sub){
                uncleFoundMessage($uncle, $sub, $uncle_miner);
            }
        }

    }

    $lastTip = $newTip;

    handleIRCMessageQueue();

    global $checks;
    foreach ($checks as $i => &$check){
        if((time() - $check["last"]) >= $check["every"]){
            $check["f"]();
            $check["last"] = time();
        }
    }
}


$context = stream_context_create([
    "socket" => [
        //"bindto" => "0:0",
        "bindto" => "[::]:0",
    ],
    "ssl" => [
        "peer_name" => IRC_SERVER_HOST,
        "verify_peer" => true,
        "verify_peer_name" => true,
        "allow_self_signed" => false,
    ],
]);

$socket = stream_socket_client("tls://".IRC_SERVER_HOST.":" . IRC_SERVER_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
//socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
//socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
if($socket === false or !is_resource($socket)/* or !socket_connect($socket, $host, 6661)*/){
    echo("[ERROR] IRCChat can't be started: $errno : ".$errstr . PHP_EOL);
    return;
}

$client = new IRCClient($socket, BOT_NICK . "_" . \random_int(0, 1000), IRC_SERVER_PASS, [
    "PRIVMSG NickServ :RECOVER ".BOT_NICK." " . BOT_PASSWORD,
    "",
    "PRIVMSG NickServ :RELEASE ".BOT_NICK." " . BOT_PASSWORD,
    "",
    "NICK " . BOT_NICK,
    "",
    "PRIVMSG NickServ :IDENTIFY ". BOT_NICK ." " . BOT_PASSWORD,
    "",
    "JOIN " . BOT_BLOCKS_FOUND_CHANNEL . "," . BOT_COMMANDS_CHANNEL,
    "",
]);

$client->run();
