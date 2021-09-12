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
                $sub = new Subscription($miner->getId(), $to);
                $database->addSubscription($sub);
                sendIRCMessage("Subscribed your nick to shares found by " . FORMAT_ITALIC . shortenAddress($maddress->getAddress()), $answer);
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
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "BLOCK FOUND:" . FORMAT_RESET . " MainChain height " . FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " :: SideChain height ". $b->getHeight() ." :: https://xmrchain.net/block/" . $b->getMainHeight() . " :: Hash " . FORMAT_ITALIC . $b->getMainHash(), BOT_BLOCKS_FOUND_CHANNEL);
    sendIRCMessage("Verify payouts using Tx private key " . FORMAT_ITALIC . $b->getTxPrivkey() . FORMAT_RESET . " :: Payout transaction for block ". FORMAT_COLOR_RED . $b->getMainHeight() . FORMAT_RESET . " https://xmrchain.net/tx/".$b->getTxId()."", BOT_BLOCKS_FOUND_CHANNEL);
}

function shareFoundMessage(Block $b, Subscription $sub, Miner $miner, array $uncles = []){
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "SHARE FOUND:" . FORMAT_RESET . " SideChain height " . FORMAT_COLOR_RED . $b->getHeight() . FORMAT_RESET . " ".(count($uncles) > 0 ? ":: Includes " . count($uncles) . " uncle(s) " : "").($b->isMainFound() ? ":: ".FORMAT_BOLD. FORMAT_COLOR_LIGHT_GREEN ." MINED MAINCHAN BLOCK " . $b->getMainHeight() . FORMAT_RESET . " " : "").":: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick());
}

function uncleFoundMessage(UncleBlock $b, Subscription $sub, Miner $miner){
    sendIRCMessage(FORMAT_COLOR_LIGHT_GREEN . FORMAT_BOLD . "UNCLE SHARE INCLUDED:" . FORMAT_RESET . " SideChain height " . FORMAT_COLOR_RED . $b->getParentHeight() . FORMAT_RESET . " :: Accounted for ".(100 - SIDECHAIN_UNCLE_PENALTY)."% of value :: Payout Address " . FORMAT_ITALIC . shortenAddress($miner->getAddress()), $sub->getNick());
}

$lastTip = null;
function handleCheck(){
    global $lastTip, $database;
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
