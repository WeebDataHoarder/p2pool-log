<?php

namespace p2pool;

use p2pool\db\Block;
use p2pool\db\Database;
use p2pool\db\Miner;
use p2pool\db\Subscription;
use p2pool\db\UncleBlock;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/constants.php";

$database = new Database($argv[1]);

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

function sendIRCMessage($message, $to, $notice = false){
    global $socket;
    $cmd = $notice ? "NOTICE" : "PRIVMSG";
    $message = str_replace(["\r", "\n", "\\r", "\\n"], "", $message);
    echo "[RAWOUT] $cmd $to :$message\n";
    fwrite($socket, "$cmd $to :$message\r\n");
    fflush($socket);
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

function si_units($number, $decimals = 3): string {
    foreach ([
        "G" => 1000000000,
        "M" => 1000000,
        "K" => 1000,
        ] as $u => $value){
        if($number >= $value){
            return number_format($number / $value, $decimals) . " " . $u;
        }
    }

    return number_format($number, $decimals);
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
                global $database;
                $maddress = null;
                try{
                    $maddress = new MoneroAddress($matches[2]);
                    if(!$maddress->verify()){
                        sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                        return;
                    }
                }catch (\Exception $e){
                    sendIRCMessage("Invalid Monero address " . $matches[2], $answer);
                    return;
                }

                $miner = $database->getOrCreateMinerByAddress($maddress->getAddress());
                $sub = new Subscription($miner->getId(), $originalSender["user"]);
                $database->addSubscription($sub);
                sendIRCMessage("Subscribed your nick to shares found by " . FORMAT_ITALIC . shortenAddress($maddress->getAddress()), $answer);
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
                }catch (\Exception $e){
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
                global $database;

                $block = $database->getLastFound();
                $payouts = getWindowPayouts($block->getHeight());
                $tip = $database->getChainTip();

                $hashrate = $tip->getDifficulty() / SIDECHAIN_BLOCK_TIME;

                sendIRCMessage("Last block found at height " . FORMAT_COLOR_RED . $block->getMainHeight() . FORMAT_RESET . " ".time_elapsed_string("@" . $block->getTimestamp()).", ".date("Y-m-d H:i:s", $block->getTimestamp())." UTC :: https://xmrchain.net/block/" . $block->getMainHeight() . " :: ".FORMAT_COLOR_ORANGE . count($payouts)." miners paid" . FORMAT_RESET . " :: Pool height ". $tip->getHeight() ." :: Pool hashrate ".si_units($hashrate)."H/s", $answer);
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.payout[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database;

                $subs = $database->getSubscriptionsFromNick($originalSender["user"]);

                if($subs === null){
                    sendIRCMessage("No known subscriptions to your nick.", $answer);
                    return;
                }

                $foundBlocks = $database->getFound(60);


                $miners = [];
                foreach ($subs as $sub) {
                    $miners[] = $database->getMiner($sub->getMiner());
                }

                $c = 0;
                foreach ($foundBlocks as $block){
                    ++$c;

                    $window_payouts = getWindowPayouts($block->getHeight());
                    $hasMiners = false;
                    foreach ($miners as $miner){
                        if(isset($window_payouts[$miner->getId()])){
                            $hasMiners = true;
                            break;
                        }
                    }

                    if(!$hasMiners){
                        continue;
                    }

                    $o = CoinbaseTransactionOutputs::fromTransactionId($block->getTxId());
                    if($o !== null){
                        $outputs = $o->matchOutputs($miners, $block->getTxPrivkey());
                        if(count($outputs) > 0){
                            $total = 0;
                            foreach ($outputs as $output){
                                $total += $output->amount;
                            }

                            $total = bcdiv((string) $total, "1000000000000", 12);

                            sendIRCMessage("Your last payout was ". FORMAT_COLOR_ORANGE . FORMAT_BOLD . $total . " XMR".FORMAT_RESET." on block ". FORMAT_COLOR_RED . $block->getMainHeight() . FORMAT_RESET ." :: ".date("Y-m-d H:i:s", $block->getTimestamp())." UTC, ".time_elapsed_string("@" . $block->getTimestamp())." :: https://xmrchain.net/block/".$block->getMainHeight()." :: Tx private key ". FORMAT_ITALIC . $block->getTxPrivkey() . FORMAT_RESET ." :: https://xmrchain.net/tx/".$block->getTxId(), $answer);
                            return;
                        }
                    }
                }


                sendIRCMessage("No known payouts to your subscriptions in the last ".$c." mined blocks.", $answer);
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_COMMANDS_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(status)[ \t]*#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $database;

                $subs = $database->getSubscriptionsFromNick($originalSender["user"]);
                $total = null;

                $payouts = getWindowPayouts();
                $myReward = 0;

                foreach ($subs as $sub){
                    $result = getShareWindowPosition($sub->getMiner());
                    $myReward += $payouts[$sub->getMiner()] ?? 0;
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
                $hashrate = $tip->getDifficulty() / SIDECHAIN_BLOCK_TIME;

                $share_count = array_sum($total[0]);
                $uncle_count = array_sum($total[1]);

                $myReward = ($myReward / array_sum($payouts));

                $myHashrate = $hashrate * $myReward;
                $myReward = (string) round($myReward * 100, 3);


                $m = "Your shares $share_count (+$uncle_count uncles) ~$myReward% " . si_units($myHashrate);

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

function getWindowPayouts(int $startBlock = null): array {
    global $database;

    $payouts = [];

    foreach($database->getBlocksInWindow($startBlock) as $block){
        if(!isset($payouts[$block->getMiner()])){
            $payouts[$block->getMiner()] = 0;
        }
        $payouts[$block->getMiner()] = gmp_add($payouts[$block->getMiner()], $block->getDifficulty());
    }
    foreach($database->getUnclesInWindow($startBlock) as $uncle){
        if(!isset($payouts[$uncle->getMiner()])){
            $payouts[$uncle->getMiner()] = 0;
        }
        $block = $database->getBlockById($uncle->getParentId());
        //TODO: use proper difficulty
        $difficulty = $database->getBlockByHeight($uncle->getHeight());
        $difficulty = $difficulty !== null ? $difficulty->getDifficulty() : 0;
        list($uncle_penalty, $rem) = gmp_div_qr(gmp_mul($difficulty, SIDECHAIN_UNCLE_PENALTY), 100);

        $payouts[$uncle->getMiner()] = gmp_add($payouts[$uncle->getMiner()], gmp_sub($block->getDifficulty(), $uncle_penalty));
        if($block !== null){
            $payouts[$block->getMiner()] = gmp_add($payouts[$block->getMiner()], $uncle_penalty);
        }
    }

    return array_map("gmp_intval", $payouts);
}

function blockFoundMessage(Block $b){
    $payouts = getWindowPayouts();

    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "BLOCK FOUND:" . FORMAT_RESET . " height " . FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " :: Pool height ". $b->getHeight() ." :: https://xmrchain.net/block/" . $b->getMainHeight() . " :: ".FORMAT_COLOR_ORANGE . count($payouts)." miners paid" . FORMAT_RESET . " :: Hash " . FORMAT_ITALIC . $b->getMainHash(), BOT_BLOCKS_FOUND_CHANNEL);
    sendIRCMessage("Verify payouts using Tx private key " . FORMAT_ITALIC . $b->getTxPrivkey() . FORMAT_RESET . " :: Payout transaction for block ". FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " https://xmrchain.net/tx/".$b->getTxId()."", BOT_BLOCKS_FOUND_CHANNEL);
    sleep(1);
}

function blockUnfoundMessage(Block $b){
    sendIRCMessage(FORMAT_COLOR_RED . FORMAT_BOLD . "BLOCK ORPHANED:" . FORMAT_RESET . " height " . FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " :: Pool height ". $b->getHeight() ." :: Pool ID " . FORMAT_ITALIC . $b->getMainHash() . FORMAT_RESET . " :: Hash " . FORMAT_ITALIC . $b->getMainHash(), BOT_BLOCKS_FOUND_CHANNEL);
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
    $positions = getShareWindowPosition($miner->getId());
    $payouts = getWindowPayouts();

    $myReward = (string) round((($payouts[$miner->getId()] ?? 0) / array_sum($payouts)) * 100, 3);

    $share_count = array_sum($positions[0]);
    $uncle_count = array_sum($positions[1]);
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "SHARE FOUND:" . FORMAT_RESET . " Pool height " . FORMAT_COLOR_RED . $b->getHeight() . FORMAT_RESET . " ".(count($uncles) > 0 ? ":: Includes " . count($uncles) . " uncle(s) " : "").($b->isMainFound() ? ":: ".FORMAT_BOLD. FORMAT_COLOR_LIGHT_GREEN ." MINED MAINCHAN BLOCK " . $b->getMainHeight() . FORMAT_RESET . " " : "").":: Your shares $share_count (+$uncle_count uncles) ~$myReward% :: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick());
}

function uncleFoundMessage(UncleBlock $b, Subscription $sub, Miner $miner){
    $positions = getShareWindowPosition($miner->getId());
    $payouts = getWindowPayouts();

    $myReward = (string) round((($payouts[$miner->getId()] ?? 0) / array_sum($payouts)) * 100, 3);

    $share_count = array_sum($positions[0]);
    $uncle_count = array_sum($positions[1]);
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "UNCLE SHARE FOUND:" . FORMAT_RESET . " Pool height " . FORMAT_COLOR_RED . $b->getParentHeight() . FORMAT_RESET . " :: Accounted for ".(100 - SIDECHAIN_UNCLE_PENALTY)."% of value :: Your shares $share_count (+$uncle_count uncles) ~$myReward% :: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick());
}

function time_elapsed_string($datetime, $full = false) {
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

            $ch = curl_init("https://xmrvsbeast.com/p2pool/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            curl_setopt($ch, CURLOPT_PROXY, "tor:9050");
            $ret = curl_exec($ch);

            $timeRemains = trim(getString($ret, '>Raffle Round Time Remaining:', '</'), "\n\t .");
            $hashRate = trim(getString($ret, '>Bonus Hash Rate:', '</'), "\n\t .");
            $addr = trim(getString($ret, '<code>4', '</'), "\n\t .");
            $addr = explode("...", "4" . $addr);

            if(count($addr) === 2 and preg_match('/^[0-9.]+[km]h\\/s$/i', $hashRate) > 0 and preg_match('/^[a-z 0-9,]+$/i', $timeRemains) > 0){
                $miner = $database->getMinerByAddressBounds($addr[0], $addr[1]);

                if($miner !== null and ($xvb_raffle === null or $xvb_raffle !== $miner->getId())){
                    $xvb_raffle = $miner->getId();

                    foreach ($database->getSubscriptionsFromMiner($miner->getId()) as $sub){
                        sendIRCMessage("You have been selected for XvB P2Pool Bonus Hash Rate Raffle :: Remaining $timeRemains :: Bonus $hashRate :: https://xmrvsbeast.com/p2pool/ :: Payout address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick());
                    }
                }
            }
        }
    ]
];

$foundBlocks = iterator_to_array($database->getFound(6));

$lastTip = null;
function handleCheck(){
    global $lastTip, $database, $foundBlocks;


    foreach ($foundBlocks as $i => $block){
        $block_db = $database->getBlockById($block->getId());
        if($block_db === null or !$block_db->isMainFound()){
            blockUnfoundMessage($block);
            unset($foundBlocks[$i]);
        }
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
            blockFoundMessage($b);
            array_pop($foundBlocks);
            if(count($foundBlocks) > 6){
                array_unshift($foundBlocks, $b);
            }
        }

        $uncles = iterator_to_array($database->getUnclesByParentId($b->getId()));

        $miner = $database->getMiner($b->getMiner());
        foreach ($database->getSubscriptionsFromMiner($miner->getId()) as $sub){
            shareFoundMessage($b, $sub, $miner, $uncles);
        }

        foreach ($uncles as $uncle){
            $uncle_miner = $database->getMiner($uncle->getMiner());
            foreach ($database->getSubscriptionsFromMiner($uncle_miner->getId()) as $sub){
                uncleFoundMessage($uncle, $sub, $uncle_miner);
            }
        }

    }

    $lastTip = $newTip;

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
