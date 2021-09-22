<?php

namespace p2pool;

class Utils {
    static function findBottomValue(callable $exists, $start = 1, $stride = 100){
        $index = $start;

        while(!$exists($index)){ //Find starting value
            $index += $stride;
        }

        $maxFound = $index;

        $min = $start;
        $max = $maxFound - 1;

        while ($min <= $max){
            $m = (int) floor(($min + $max) / 2);

            $r = !$exists($m);
            if($r){
                $min = $m + 1;
            }else{
                $max = $m - 1;
            }
        }

        return $min;
    }
    static function findTopValue(callable $exists, $start = 1, $stride = 100){
        $index = $start;

        while(!$exists($index)){ //Find starting value
            $index += $stride;
        }

        $minFound = $index;

        while($exists($index)){
            $minFound = $index;
            $index *= 2;
        }

        $maxNotFound = $index;

        $baseIndex = $minFound;

        $min = $minFound - $baseIndex;
        $max = $maxNotFound - $baseIndex - 1;

        while ($min <= $max){
            $m = (int) floor(($min + $max) / 2);

            $r = $exists($m + $baseIndex);
            if($r){
                $min = $m + 1;
            }else{
                $max = $m - 1;
            }
        }

        return $max + $baseIndex;
    }

    static function moneroRPC(string $method, array $params){
        $ch = curl_init(getenv("MONEROD_RPC_URL") . $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return @json_decode(curl_exec($ch));
    }

    static function encodeBinaryNumber(int $i): string {
        $v = gmp_strval($i, 62);

        return strlen($v) >= strlen((string) $i) ? (string) $i : (preg_match("#^[0-9]+$#", $v) > 0 ? ".$v" : $v);
    }

    static function decodeBinaryNumber(string $i): int {
        if(preg_match("#^[0-9]+$#", $i) > 0){
            return (int) $i;
        }
        return gmp_intval(gmp_init(str_replace(".", "", $i), 62));
    }

    static function si_units($number, $decimals = 3): string {
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

    static function time_elapsed_string_short($datetime): string {
        $now = new \DateTime;
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);
        $s = str_pad($diff->h, 2, "0", STR_PAD_LEFT) . ":"
            . str_pad($diff->i, 2, "0", STR_PAD_LEFT) . ":"
            . str_pad($diff->s, 2, "0", STR_PAD_LEFT);

        if($diff->d or $diff->m or $diff->y){
            $s = $diff->d . ":" . $s;
        }
        if($diff->m or $diff->y){
            $s = $diff->m . ":" . $s;
        }
        if($diff->y){
            $s = $diff->y . ":" . $s;
        }

        return $s;
    }

    static function time_elapsed_string($datetime, bool $full = false): string {
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
}