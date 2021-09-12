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
}