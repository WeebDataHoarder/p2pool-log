<?php

namespace p2pool;

class IRCClient{
    public $msg;
    public $response;
    public $type;
    private $socket, $nickname, $password, $stop, $status, $cmd;
    public function __construct($socket, $nickname, $password, array $joinCommands = []){
        $this->stop = false;
        $this->msg = "";
        $this->response = "";
        $this->type = 0;
        $this->socket = $socket;
        $this->nickname = $nickname;
        $this->password = $password === "" ? false:$password;
        $this->cmd = $joinCommands;
        $this->status = 0;
        stream_set_blocking($this->socket, 0);
    }

    private function log($msg, $type = 0){
        echo $msg . PHP_EOL;
    }

    public function run(){
        $connect = "";
        if($this->password !== false){
            $connect .= "PASS ".$this->password."\r\n";
        }
        $connect .= "NICK ".$this->nickname."\r\n";
        $connect .= "USER ".BOT_USER." 0 * :".BOT_USER."\r\n";
        fwrite($this->socket, $connect);
        $host = "";
        $lastCheck = 0;
        while(true){
            $line = fgets($this->socket);
            if($line != "" and trim($line) != ""){
                $this->log("[RAW] " . trim($line));
                $line = explode(" ", $line);
                $cmd = array_shift($line);
                $sender = "";
                $senderCloak = "";
                if($cmd !== "" and $cmd[0] == ":"){
                    $end = strpos($cmd, "!");
                    if($end === false){
                        $end = strlen($cmd);
                    }
                    $sender = substr($cmd, 1, $end - 1);
                    $senderCloak = substr($cmd, $end + 1);
                    if($host === ""){
                        $host = $sender;
                    }
                    $cmd = array_shift($line);
                }
                $msg = implode(" ", $line);
                switch(strtoupper($cmd)){
                    case "JOIN":
                        if($sender === $this->nickname){
                            $this->log("[INFO] Joined channel $msg");
                        }else{
                            if(preg_match("/^([^ ]+) (\x01:ACTION |:|)(.+)$/iu", $msg, $matches) > 0){
                                echo "<$sender!$senderCloak:$msg> * joined\n";
                                handleNewJoin($sender, $senderCloak, $msg);
                            }
                        }
                        break;
                    case "332": //Topic
                        array_shift($line);
                        $from = array_shift($line);
                        $mes = substr($msg, strpos($msg, ":") + 1);
                        $this->log("[INFO] $from topic: $mes");
                        break;
                    case "QUIT":
                    case "PART":
                        //$this->log(":$sender left the channel");
                        break;
                    case "MODE":
                        if($this->status === 0){
                            $this->status = 1;
                        }
                        $this->log("[INFO] Mode $msg");
                        break;
                    case "PING":
                        echo "[RAWOUT] PONG $msg\n";
                        fwrite($this->socket, "PONG ".$msg."\r\n");
                        break;
                    case "NOTICE":
                        break;
                    case "PRIVMSG":
                        if(preg_match("/^([^ ]+) :\x01(.+)\x01/iu", $msg, $matches) > 0){ //CTCP
                            $target = $matches[1];
                            $msg = $matches[2];

                            echo "<CTCP $sender!$senderCloak:$target> $msg\n";
                            handleNewCTCP($sender, $senderCloak, $target, trim($msg, "\x01\r\n"));
                        }else if(preg_match("/^([^ ]+) (\x01:ACTION |:|)(.+)$/iu", $msg, $matches) > 0){
                            $target = $matches[1];
                            $type = $matches[2];
                            $msg = $matches[3];

                            echo "<$sender!$senderCloak:$target> $msg\n";
                            handleNewMessage($sender, $senderCloak, $target, trim($msg, "\x01\r\n"), $type === "\x01:ACTION ");

                            if($target === $this->nickname){
                                //TODO
                            }
                        }
                        break;
                    default:
                        break;
                }
            }
            if($this->status >= 1 and $this->status < 500){
                ++$this->status;
            }
            if($this->status === 500){
                sleep(1);
                foreach($this->cmd as $cmd){
                    if($cmd === ""){
                        sleep(1);
                        continue;
                    }
                    echo "[RAWOUT] $cmd\n";
                    fwrite($this->socket, $cmd."\r\n");
                    usleep(50000);
                }
                $this->status = 501;
            }
            usleep(10000);
            if(feof($this->socket) or !$this->socket){
                exit();
            }
            if($this->status === 501 and $lastCheck <= (time() - 1)){
                $lastCheck = time();
                handleCheck();
            }
        }
    }
}