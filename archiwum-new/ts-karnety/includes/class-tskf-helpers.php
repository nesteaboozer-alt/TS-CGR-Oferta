<?php
class TSKF_Helpers {
    static function code_prefix(){ return 'TSB'; } // nowy prefix

    static function now_mysql(){ return current_time('mysql'); }

    static function gen_code($prefix=null){
        if(!$prefix) $prefix = self::code_prefix();
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        // TSB-XX-XX => 2 znaki + 2 znaki
        $part1 = '';
        $part2 = '';
        for($i=0;$i<2;$i++){
            $part1 .= $alphabet[random_int(0, strlen($alphabet)-1)];
            $part2 .= $alphabet[random_int(0, strlen($alphabet)-1)];
        }

        return $prefix.'-'.$part1.'-'.$part2;
    }
}

