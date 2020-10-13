<?php
// https://www.php.net/manual/en/function.ksort.php#109398
function array_reorder_keys(&$array, $keynames){
    if(empty($array) || !is_array($array) || empty($keynames)) return;
    if(!is_array($keynames)) $keynames = explode(',',$keynames);
    if(!empty($keynames)) $keynames = array_reverse($keynames);
    foreach($keynames as $n){
        if(array_key_exists($n, $array)){
            $newarray = array($n=>$array[$n]); //copy the node before unsetting
            unset($array[$n]); //remove the node
            $array = $newarray + array_filter($array); //combine copy with filtered array
        }
    }
}
